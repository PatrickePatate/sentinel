<?php

namespace App\Jobs;

use App\Ai\Budget;
use App\Ai\ScanRunner;
use App\Ai\Severity;
use App\Models\AgentRun;
use App\Models\Finding;
use App\Models\Machine;
use App\Notifications\Notifier;
use App\Ssh\Probe\StateFingerprint;
use App\Ssh\Probe\WebstackPrecheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RunScan implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $machineId, public string $objective, public ?int $userId = null, public string $trigger = 'manual', public ?int $runId = null, public string $profile = 'audit') {}

    public function failed(Throwable $e): void
    {
        // e.g. the worker was killed or the machine vanished: never leave a run looking active forever.
        AgentRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'report' => 'The scan job failed before finishing: '.$e->getMessage(), 'progress' => null]);
    }

    public function handle(ScanRunner $runner): void
    {
        // Queue workers have no session: attribute the audit trail to whoever asked for the scan.
        if ($this->userId) {
            Auth::onceUsingId($this->userId);
        }

        if ($this->precheckClears()) {
            app(Notifier::class)->scanFinished(AgentRun::find($this->runId)->load('machine'));

            return;
        }

        $fingerprint = $this->stateFingerprint();

        if ($fingerprint !== null && $this->unchanged($fingerprint)) {
            app(Notifier::class)->scanFinished(AgentRun::find($this->runId)->load('machine'));

            return;
        }

        $run = $runner->run(Machine::findOrFail($this->machineId), $this->objective, trigger: $this->trigger, run: $this->runId ? AgentRun::find($this->runId) : null, profile: $this->profile);

        if ($fingerprint !== null) {
            $run->update(['state_fingerprint' => $fingerprint]);
        }

        app(Notifier::class)->scanFinished($run->load('machine'));
        $this->escalate($run, $runner);
    }

    /** Only scheduled audits hash the machine state: a human asking for a scan always gets the AI. */
    private function stateFingerprint(): ?string
    {
        if (! config('sentinel.scheduling.skip_unchanged.enabled') || $this->trigger !== 'scheduled' || $this->profile !== 'audit' || ! $this->runId) {
            return null;
        }

        try {
            return app(StateFingerprint::class)->compute(Machine::findOrFail($this->machineId));
        } catch (Throwable) {
            return null; // unreachable: the normal path records the failure
        }
    }

    /**
     * Closes the run without an AI call when the latest AI audit of the machine is recent and saw this exact state.
     * Its verdict and its open findings carry over unchanged, so no notification repeats them.
     */
    private function unchanged(string $fingerprint): bool
    {
        $last = AgentRun::where('machine_id', $this->machineId)->where('profile', 'audit')->where('status', 'completed')
            ->whereNotIn('provider', ['unchanged', 'precheck'])->whereNotNull('severity')
            ->latest('id')->first();

        if (! $last || $last->state_fingerprint !== $fingerprint || $last->created_at->lt(now()->subHours(config('sentinel.scheduling.skip_unchanged.max_age_hours')))) {
            return false;
        }

        $open = Finding::where('machine_id', $this->machineId)->where('profile', 'audit')->unresolved()->pluck('id')->all();

        AgentRun::whereKey($this->runId)->first()->update([
            'provider' => 'unchanged', 'model' => null, 'status' => 'completed', 'progress' => null,
            'severity' => $last->severity, 'state_fingerprint' => $fingerprint,
            'summary' => "Nothing changed since scan #{$last->id} (same machine state); its verdict stands. No AI call.",
            'report' => "Quick check, no AI call: the security-relevant state of the machine (failed units, listening ports, SSH settings, accounts, cron, pending security updates, kernel) is exactly what scan #{$last->id} audited {$last->created_at->diffForHumans()}.\n\nVerdict carried over: {$last->severity}. A full AI audit runs again once that scan is ".config('sentinel.scheduling.skip_unchanged.max_age_hours').' hours old, or as soon as something changes.',
            'findings_diff' => ['new' => [], 'escalated' => [], 'resolved' => [], 'ongoing' => $open],
        ]);

        return true;
    }

    /**
     * A serious verdict from the cheaper scheduled model is checked again by the main one, which is the one people
     * trust: it either confirms the problem (and is notified like any scan) or explains why it is not one.
     */
    private function escalate(AgentRun $run, ScanRunner $runner): void
    {
        $threshold = Severity::tryFrom((string) config('sentinel.agent.escalate_severity'));
        $severity = Severity::tryFrom((string) $run->severity);
        $cheaper = config('sentinel.agent.scheduled_provider') !== null
            && [config('sentinel.agent.scheduled_provider'), config('sentinel.agent.scheduled_model')] !== [config('sentinel.agent.provider'), config('sentinel.agent.model')];

        if ($this->trigger !== 'scheduled' || ! $threshold || ! $severity || ! $cheaper || $run->status !== 'completed' || ! $severity->atLeast($threshold)) {
            return;
        }

        // Its summary is left out on purpose: model text, possibly steered by what it read, must not become the objective
        // the risk gate checks actions against.
        $objective = "Run a security and health audit. Scan #{$run->id}, made by a smaller model, reported a {$severity->value} problem: "
            .'confirm it or refute it with your own evidence.';
        $escalation = $runner->queue($run->machine, $objective, 'escalation', $this->profile);
        self::dispatch($run->machine_id, $objective, null, 'escalation', $escalation->id, $this->profile);
    }

    /**
     * Scheduled web server checks start with a plain-code look at the stack. When everything is fine, the run is closed as
     * healthy without calling the model, except for a full AI check once every few hours (it also notices what a plain
     * check cannot: a component the machine notes expect but that is not there, odd log lines...).
     */
    private function precheckClears(): bool
    {
        $settings = config('sentinel.scheduling.precheck');

        if (! $settings['enabled'] || $this->trigger !== 'scheduled' || $this->profile !== 'webserver' || ! $this->runId) {
            return false;
        }

        $machine = Machine::findOrFail($this->machineId);

        try {
            $probe = app(WebstackPrecheck::class);
            $result = $probe->run($machine);
            $probe->recordDisk($machine, $result['disk']);
        } catch (Throwable) {
            return false; // unreachable machines get the normal path, which records the failure
        }

        // 0 hours: the model is only called when the plain check finds a problem.
        $hours = $machine->fullCheckHours();
        $fullCheckDue = $hours > 0 && ! AgentRun::where('machine_id', $machine->id)->where('profile', 'webserver')->where('provider', '!=', 'precheck')
            ->where('status', 'completed')->where('created_at', '>', now()->subHours($hours))->exists();

        // A healthy stack over budget waits for next month's routine AI check; a broken one is always looked at.
        $fullCheckDue = $fullCheckDue && ! app(Budget::class)->exceeded($machine);

        if (! $result['healthy'] || $fullCheckDue) {
            return false;
        }

        AgentRun::whereKey($this->runId)->update([
            'provider' => 'precheck', 'status' => 'completed', 'severity' => 'none', 'progress' => null,
            'summary' => 'Everything looks healthy (plain check, no AI call).',
            'report' => "Quick check, no AI call: every web stack unit that should run is running, configuration tests pass and the site answers.\n\n".$result['report'],
        ]);

        return true;
    }
}
