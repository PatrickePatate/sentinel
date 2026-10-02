<?php

namespace App\Monitoring;

use App\Models\Machine;
use App\Models\MachineMetric;
use App\Ssh\SshTransport;

/**
 * Samples a few health numbers on a machine with one fixed, read-only command (no sudo, no LLM), so trends can be
 * charted and alerted on without waiting for a scan.
 */
class MetricsCollector
{
    /** Metric name => human label and unit. */
    public const METRICS = [
        'disk_root_percent' => ['Root filesystem', '%'],
        'inode_root_percent' => ['Root inodes', '%'],
        'memory_used_percent' => ['Memory', '%'],
        'swap_used_percent' => ['Swap', '%'],
        'load_per_cpu' => ['Load per CPU', ''],
        'updates_pending' => ['Pending updates', ''],
        'security_updates_pending' => ['Security updates', ''],
        'reboot_required' => ['Reboot required', ''],
    ];

    public const RETENTION_DAYS = 60;

    public function __construct(private SshTransport $transport) {}

    public function command(): string
    {
        return <<<'SH'
# sentinel-metrics
echo "disk_root_percent $(df -P / | awk 'NR==2 {print $5}' | tr -dc 0-9)"
echo "inode_root_percent $(df -Pi / | awk 'NR==2 {print $5}' | tr -dc 0-9)"
free -b | awk '/^Mem:/ {if ($2 > 0) printf "memory_used_percent %.1f\n", ($2 - $7) * 100 / $2} /^Swap:/ {if ($2 > 0) printf "swap_used_percent %.1f\n", $3 * 100 / $2; else print "swap_used_percent 0"}'
echo "load_per_cpu $(awk -v n="$(nproc)" '{printf "%.2f", $1 / n}' /proc/loadavg)"
command -v apt-get >/dev/null && {
    echo "updates_pending $(apt list --upgradable 2>/dev/null | tail -n +2 | grep -c .)"
    echo "security_updates_pending $(apt-get -s upgrade 2>/dev/null | grep -c '^Inst .*securi')"
}
test -f /var/run/reboot-required && echo "reboot_required 1" || echo "reboot_required 0"
SH;
    }

    /** @return array<string, float> */
    public function interpret(string $output): array
    {
        $values = [];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            [$name, $value] = preg_split('/\s+/', trim($line), 2) + [null, null];

            // Only known names and plain numbers: anything else in the output is ignored.
            if (isset(self::METRICS[$name]) && is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value)) {
                $values[$name] = (float) $value;
            }
        }

        return $values;
    }

    /** @return array<string, float> What was recorded. */
    public function collect(Machine $machine): array
    {
        $values = $this->interpret($this->transport->run($machine, $this->command(), 40)->output);
        $now = now();

        foreach ($values as $name => $value) {
            MachineMetric::create(['machine_id' => $machine->id, 'name' => $name, 'value' => $value, 'recorded_at' => $now]);
        }

        MachineMetric::where('machine_id', $machine->id)->where('recorded_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();

        return $values;
    }
}
