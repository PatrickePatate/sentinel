<?php

namespace App\Jobs;

use App\Ai\ScanRunner;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Notifications\Notifier;
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

        $run = $runner->run(Machine::findOrFail($this->machineId), $this->objective, trigger: $this->trigger, run: $this->runId ? AgentRun::find($this->runId) : null, profile: $this->profile);

        app(Notifier::class)->scanFinished($run->load('machine'));
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
