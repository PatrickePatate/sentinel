<?php

namespace App\Findings;

use App\Ai\Severity;
use App\Models\AgentRun;
use App\Models\Finding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies what a completed scan reported to the machine's findings, and records the difference on the run:
 * new (first seen, or back after being resolved), escalated (worse than before), resolved (no longer reported)
 * and ongoing (reported again, unchanged or better, or missed once and not yet resolved).
 */
class FindingTracker
{
    public function reconcile(AgentRun $run): void
    {
        // A failed or verdict-less scan proves nothing: it must not resolve anything.
        if ($run->status !== 'completed' || $run->severity === null || $run->findings_diff !== null) {
            return;
        }

        $profile = $run->profile ?? 'audit';
        $reported = $run->reported_findings ?? [];
        $diff = ['new' => [], 'escalated' => [], 'resolved' => [], 'ongoing' => []];

        // The verdict cannot be lower than the worst finding the same scan reported: a problem the agent recorded cannot be
        // talked down to "nothing to report" afterwards (e.g. by text planted in a log it read later). Muted ones do not count.
        $floor = Severity::from($run->severity);

        DB::transaction(function () use ($run, $profile, $reported, &$diff, &$floor) {
            $known = Finding::where('machine_id', $run->machine_id)->where('profile', $profile)->get()->keyBy('key');
            $reported = $this->matchRenamedKeys($reported, $known);

            foreach ($reported as $key => $report) {
                $finding = $known->get($key);
                $seen = ['title' => $report['title'], 'evidence' => $report['evidence'], 'last_seen_run_id' => $run->id, 'last_seen_at' => now()];

                if (! $finding) {
                    $finding = Finding::create($seen + [
                        'machine_id' => $run->machine_id, 'profile' => $profile, 'key' => $key, 'severity' => $report['severity'],
                        'status' => 'open', 'first_seen_run_id' => $run->id, 'first_seen_at' => now(), 'occurrences' => 1,
                    ]);
                    $diff['new'][] = $finding->id;
                    $floor = $this->worst($floor, $finding->severityLevel());

                    continue;
                }

                // Back soon after being resolved (a flapping check) is the same episode: it is not announced again.
                $reopened = $finding->status === 'resolved'
                    && ! $finding->resolved_at?->isAfter(now()->subDays(config('sentinel.findings.reopen_quiet_days')));

                $worse = Severity::from($report['severity'])->rank() > $finding->severityLevel()->rank();
                $status = match (true) {
                    // Accepted is a decision to live with the risk: a worse report does not undo it.
                    $finding->status === 'accepted' => 'accepted',
                    $finding->status === 'resolved' => 'open',
                    // A problem that got worse is worth a fresh look, even if someone acknowledged or muted it.
                    $worse => 'open',
                    $finding->status === 'muted' && ! $finding->isMuted() => 'open',
                    default => $finding->status,
                };

                $diff[match (true) {
                    $reopened => 'new',
                    $worse && $status !== 'accepted' => 'escalated',
                    default => 'ongoing',
                }][] = $finding->id;

                $finding->update($seen + [
                    'severity' => $report['severity'],
                    'status' => $status,
                    'muted_until' => $status === 'muted' ? $finding->muted_until : null,
                    'resolved_run_id' => null,
                    'resolved_at' => null,
                    'occurrences' => $finding->occurrences + 1,
                    'misses' => 0,
                ] + ($reopened ? ['notified_severity' => null, 'last_notified_at' => null] : []));

                if ($finding->needsAttention()) {
                    $floor = $this->worst($floor, $finding->severityLevel());
                }
            }

            // Silence only means "fixed" when the scan used the tool or found nothing at all: an agent that wrote a
            // medium verdict without listing its findings has not shown that the known ones are gone.
            $absenceMeansFixed = $reported !== [] || $run->severity === Severity::None->value;
            // A clean scan (verdict none, nothing reported) resolves at once; otherwise one missed scan may be a slip.
            $cleanScan = $reported === [] && $run->severity === Severity::None->value;

            $gone = $known->reject(fn (Finding $f) => isset($reported[$f->key]) || $f->status === 'resolved');

            foreach ($absenceMeansFixed ? $gone : [] as $finding) {
                $misses = $finding->misses + 1;

                if (! $cleanScan && $misses < config('sentinel.findings.misses_before_resolved')) {
                    $finding->update(['misses' => $misses]);
                    $diff['ongoing'][] = $finding->id;

                    continue;
                }

                $finding->update(['status' => 'resolved', 'muted_until' => null, 'resolved_run_id' => $run->id, 'resolved_at' => now(), 'misses' => 0]);
                $diff['resolved'][] = $finding->id;
            }

            $run->update(['findings_diff' => $diff, 'severity' => $floor->value]);
        });
    }

    /**
     * Points a reported key that this machine does not know at the known finding it renames, when the words are close
     * enough. Each known finding can be claimed by one renamed key only.
     *
     * @param  array<string, array<string, mixed>>  $reported
     * @param  Collection<string, Finding>  $known
     * @return array<string, array<string, mixed>>
     */
    private function matchRenamedKeys(array $reported, Collection $known): array
    {
        $threshold = config('sentinel.findings.same_key_similarity');
        $since = now()->subDays(config('sentinel.findings.same_key_days'));

        // Only findings still on the machine, or resolved recently enough to be the same problem coming back.
        $candidates = $known->reject(fn (Finding $f) => isset($reported[$f->key])
            || ($f->status === 'resolved' && ! $f->resolved_at?->isAfter($since)));

        $renamed = [];
        foreach ($reported as $key => $report) {
            if ($known->has($key)) {
                continue;
            }

            $best = null;
            $bestScore = 0.0;
            foreach ($candidates as $candidateKey => $candidate) {
                $score = $this->similarity((string) $key, (string) $candidateKey);

                if ($score >= $threshold && $score > $bestScore) {
                    [$best, $bestScore] = [$candidateKey, $score];
                }
            }

            if ($best !== null) {
                $renamed[$key] = $best;
                $candidates->forget($best);
            }
        }

        $matched = [];
        foreach ($reported as $key => $report) {
            $matched[$renamed[$key] ?? $key] = $report;
        }

        return $matched;
    }

    /** Share of the words two keys have in common, from 0 to 1 (Jaccard index). */
    private function similarity(string $a, string $b): float
    {
        $first = array_unique(explode('-', $a));
        $second = array_unique(explode('-', $b));

        return count(array_intersect($first, $second)) / count(array_unique([...$first, ...$second]));
    }

    private function worst(Severity $a, Severity $b): Severity
    {
        return $b->rank() > $a->rank() ? $b : $a;
    }
}
