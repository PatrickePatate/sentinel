<?php

namespace App\Jobs;

use App\Ai\ScanRunner;
use App\Models\AgentRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** A follow-up message on a scan report, run on a worker like the scan itself. */
class RunFollowUp implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $runId, public ?int $userId = null) {}

    public function failed(Throwable $e): void
    {
        AgentRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'report' => 'The follow-up job failed before finishing: '.$e->getMessage(), 'progress' => null]);
    }

    public function handle(ScanRunner $runner): void
    {
        if ($this->userId) {
            Auth::onceUsingId($this->userId);
        }

        $runner->followUp(AgentRun::with('parent.machine', 'machine')->findOrFail($this->runId));
    }
}
