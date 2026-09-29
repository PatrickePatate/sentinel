<?php

namespace App\Console\Commands;

use App\Ai\ScanRunner;
use App\Models\Machine;
use Illuminate\Console\Command;

class ScanMachine extends Command
{
    protected $signature = 'sentinel:scan {machine : Machine id} {--objective=Run a security and health audit} {--provider=} {--model=}';

    protected $description = 'Let the AI agent audit a machine through the read-only tool catalog';

    public function handle(ScanRunner $runner): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        $run = $runner->run($machine, $this->option('objective'), $this->option('provider') ?: null, $this->option('model') ?: null);

        $this->line($run->report ?? 'No report.');

        return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
