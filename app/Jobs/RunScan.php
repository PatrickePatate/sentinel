<?php

namespace App\Jobs;

use App\Ai\ScanRunner;
use App\Models\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

class RunScan implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $machineId, public string $objective, public ?int $userId = null) {}

    public function handle(ScanRunner $runner): void
    {
        // Queue workers have no session: attribute the audit trail to whoever asked for the scan.
        if ($this->userId) {
            Auth::onceUsingId($this->userId);
        }

        $runner->run(Machine::findOrFail($this->machineId), $this->objective);
    }
}
