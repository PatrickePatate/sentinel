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

        foreach (config('sentinel.scheduling.profiles') as $profile => $settings) {
            $interval = $settings['interval_column'];
            $last = $settings['last_column'];

            Machine::query()
                ->when($settings['enabled_column'] ?? null, fn ($q, $column) => $q->where($column, true))
                ->whereNotNull($interval)->where($interval, '>', 0)
                ->whereNull('revoked_at')->whereNotNull('host_key_fingerprint')
                // Planned work: someone is changing the machine on purpose, so scans would only report the work in progress.
                ->where(fn ($q) => $q->whereNull('maintenance_until')->orWhere('maintenance_until', '<=', now()))
                ->get()
                ->each(function (Machine $machine) use (&$queued, $profile, $settings, $interval, $last) {
                    $lastAt = $machine->{$last};
                    $due = $lastAt === null || $lastAt->copy()->addMinutes($machine->{$interval})->lte(now());

                    if (! $due || $this->alreadyRunning($machine)) {
                        return;
                    }

                    // Compare-and-set: two overlapping schedulers cannot both queue the same scan.
                    $claimed = Machine::whereKey($machine->id)
                        ->where(fn ($q) => $lastAt ? $q->where($last, $lastAt) : $q->whereNull($last))
                        ->update([$last => now()]);

                    if ($claimed === 1) {
                        $run = app(ScanRunner::class)->queue($machine, $settings['objective'], 'scheduled', $profile);
                        RunScan::dispatch($machine->id, $settings['objective'], null, 'scheduled', $run->id, $profile);
                        $queued++;
                    }
                });
        }

        $this->info("{$queued} scan(s) queued.");

        return self::SUCCESS;
    }

    private function alreadyRunning(Machine $machine): bool
    {
        return AgentRun::where('machine_id', $machine->id)->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subMinutes(30))->exists();
    }
}
