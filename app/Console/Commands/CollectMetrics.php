<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Monitoring\MetricsCollector;
use App\Monitoring\TrendWatcher;
use Illuminate\Console\Command;
use Throwable;

class CollectMetrics extends Command
{
    protected $signature = 'sentinel:collect-metrics {machine? : Only this machine (id or name)}';

    protected $description = 'Sample disk, memory, load and pending updates on every machine and alert on worrying trends';

    public function handle(MetricsCollector $collector, TrendWatcher $watcher): int
    {
        $machines = Machine::whereNull('revoked_at')->whereNotNull('host_key_fingerprint')
            ->when($this->argument('machine'), fn ($q, $machine) => $q->where(fn ($q) => $q->whereKey($machine)->orWhere('name', $machine)))
            ->get();

        foreach ($machines as $machine) {
            try {
                $values = $collector->collect($machine);
                $watcher->check($machine);
                $this->line("{$machine->name}: ".count($values).' metric(s)');
            } catch (Throwable $e) {
                // An unreachable machine is the scans' business (they record and report it); keep sampling the others.
                $this->warn("{$machine->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
