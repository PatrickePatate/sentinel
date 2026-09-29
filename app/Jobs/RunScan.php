<?php

namespace App\Jobs;

use App\Ai\ScanRunner;
use App\Models\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunScan implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $machineId, public string $objective) {}

    public function handle(ScanRunner $runner): void
    {
        $runner->run(Machine::findOrFail($this->machineId), $this->objective);
    }
}
