<?php

namespace App\Findings;

use App\Ai\Severity;
use App\Models\AgentRun;
use App\Models\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Applies what a completed scan reported to the machine's findings, and records the difference on the run:
 * new (first seen, or back after being resolved), escalated (worse than before), resolved (no longer reported)
 * and ongoing (reported again, unchanged or better).
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

                $worse = Severity::from($report['severity'])->rank() > $finding->severityLevel()->rank();
                $status = match (true) {
                    $finding->status === 'resolved' => 'open',
                    // A problem that got worse is worth a fresh look, even if someone acknowledged or muted it.
                    $worse => 'open',
                    $finding->status === 'muted' && ! $finding->isMuted() => 'open',
                    default => $finding->status,
                };

                $diff[match (true) {
                    $finding->status === 'resolved' => 'new',
                    $worse => 'escalated',
                    default => 'ongoing',
                }][] = $finding->id;

                $finding->update($seen + [
                    'severity' => $report['severity'],
                    'status' => $status,
                    'muted_until' => $status === 'muted' ? $finding->muted_until : null,
                    'resolved_run_id' => null,
                    'resolved_at' => null,
                    'occurrences' => $finding->occurrences + 1,
                ]);

                if ($status !== 'muted') {
                    $floor = $this->worst($floor, $finding->severityLevel());
                }
            }

            // Silence only means "fixed" when the scan used the tool or found nothing at all: an agent that wrote a
            // medium verdict without listing its findings has not shown that the known ones are gone.
            $absenceMeansFixed = $reported !== [] || $run->severity === Severity::None->value;

            $gone = $known->reject(fn (Finding $f) => isset($reported[$f->key]) || $f->status === 'resolved');

            foreach ($absenceMeansFixed ? $gone : [] as $finding) {
                $finding->update(['status' => 'resolved', 'muted_until' => null, 'resolved_run_id' => $run->id, 'resolved_at' => now()]);
                $diff['resolved'][] = $finding->id;
            }

            $run->update(['findings_diff' => $diff, 'severity' => $floor->value]);
        });
    }

    private function worst(Severity $a, Severity $b): Severity
    {
        return $b->rank() > $a->rank() ? $b : $a;
    }
}
