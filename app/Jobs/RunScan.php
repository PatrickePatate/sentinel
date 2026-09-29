<?php

namespace App\Jobs;

use App\Ai\ScanRunner;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RunScan implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $machineId, public string $objective, public ?int $userId = null, public string $trigger = 'manual', public ?int $runId = null) {}

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

        $run = $runner->run(Machine::findOrFail($this->machineId), $this->objective, trigger: $this->trigger, run: $this->runId ? AgentRun::find($this->runId) : null);

        app(Notifier::class)->scanFinished($run->load('machine'));
    }
}
