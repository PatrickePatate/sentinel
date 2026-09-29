<?php

namespace App\Console\Commands;

use App\Ai\ScanRunner;
use App\Jobs\RunScan;
use App\Models\AgentRun;
use App\Models\Machine;
use Illuminate\Console\Command;

class ScanDueMachines extends Command
{
    protected $signature = 'sentinel:scan-due';

    protected $description = 'Queue an autonomous scan for every machine whose configured frequency has elapsed';

    public function handle(): int
    {
        $queued = 0;

        Machine::query()
            ->whereNotNull('scan_interval_minutes')->where('scan_interval_minutes', '>', 0)
            ->whereNull('revoked_at')->whereNotNull('host_key_fingerprint')
            ->get()
            ->each(function (Machine $machine) use (&$queued) {
                $due = $machine->last_scan_at === null || $machine->last_scan_at->copy()->addMinutes($machine->scan_interval_minutes)->lte(now());

                if (! $due || $this->alreadyRunning($machine)) {
                    return;
                }

                // Compare-and-set: two overlapping schedulers cannot both queue the same scan.
                $claimed = Machine::whereKey($machine->id)
                    ->where(fn ($q) => $machine->last_scan_at ? $q->where('last_scan_at', $machine->last_scan_at) : $q->whereNull('last_scan_at'))
                    ->update(['last_scan_at' => now()]);

                if ($claimed === 1) {
                    $run = app(ScanRunner::class)->queue($machine, config('sentinel.scheduling.objective'), 'scheduled');
                    RunScan::dispatch($machine->id, config('sentinel.scheduling.objective'), null, 'scheduled', $run->id);
                    $queued++;
                }
            });

        $this->info("{$queued} scan(s) queued.");

        return self::SUCCESS;
    }

    private function alreadyRunning(Machine $machine): bool
    {
        return AgentRun::where('machine_id', $machine->id)->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subMinutes(30))->exists();
    }
}
