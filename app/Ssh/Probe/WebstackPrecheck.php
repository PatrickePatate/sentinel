<?php

namespace App\Ssh\Probe;

use App\Models\Machine;
use App\Models\MachineMetric;
use App\Ssh\SshTransport;

/**
 * A cheap, deterministic look at the web stack: no LLM involved. A scheduled web server check that finds nothing wrong
 * here does not need a model call, which is what makes a 15 minute schedule affordable.
 */
class WebstackPrecheck
{
    private const UNITS = "'nginx*' 'apache2*' 'httpd*' 'php*-fpm*' 'mysql*' 'mariadb*' 'postgresql*' 'redis*' 'valkey*' 'memcached*' 'supervisor*' 'rabbitmq*' 'elasticsearch*' 'opensearch*' 'meilisearch*'";

    public function __construct(private SshTransport $transport) {}

    public function command(): string
    {
        $sudo = config('sentinel.actions.use_sudo') ? 'sudo -n ' : '';
        $wrapper = '/usr/local/sbin/sentinel-web-config';

        return <<<SH
# sentinel-precheck
echo "disk \$(df --output=pcent / | tail -n 1 | tr -dc 0-9)"
for u in \$(systemctl list-units --type=service --all --no-pager --plain --no-legend {$this->units()} 2>/dev/null | awk '\$2 != "not-found" {print \$1}'); do
    echo "unit \$u \$(systemctl is-active \$u 2>/dev/null) \$(systemctl is-enabled \$u 2>/dev/null | head -n 1)"
done
command -v nginx >/dev/null && { {$sudo}{$wrapper} test nginx >/dev/null 2>&1 && echo "config nginx ok" || echo "config nginx fail"; }
command -v apache2ctl >/dev/null && { {$sudo}{$wrapper} test apache2 >/dev/null 2>&1 && echo "config apache2 ok" || echo "config apache2 fail"; }
ls /usr/sbin/php-fpm* >/dev/null 2>&1 && { {$sudo}{$wrapper} test php-fpm >/dev/null 2>&1 && echo "config php-fpm ok" || echo "config php-fpm fail"; }
if systemctl is-active --quiet nginx 2>/dev/null || systemctl is-active --quiet apache2 2>/dev/null; then
    echo "http \$(curl -sS -k -m 5 -o /dev/null -w '%{http_code}' http://127.0.0.1/ 2>/dev/null)"
fi
SH;
    }

    /** @return array{healthy: bool, problems: list<string>, report: string, disk: ?float} */
    public function run(Machine $machine): array
    {
        $result = $this->transport->run($machine, $this->command(), 40);

        return $this->interpret($result->output);
    }

    /** @return array{healthy: bool, problems: list<string>, report: string, disk: ?float} */
    public function interpret(string $output): array
    {
        $problems = [];
        $lines = [];
        $disk = null;

        foreach (preg_split('/\R/', trim($output)) as $line) {
            $parts = preg_split('/\s+/', trim($line));

            switch ($parts[0] ?? '') {
                case 'disk':
                    $disk = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : null;

                    break;
                case 'unit':
                    [, $unit, $state, $enabled] = $parts + [null, '?', '?', ''];
                    $lines[] = "{$unit}: {$state}".($enabled ? " ({$enabled})" : '');

                    if ($state === 'failed' || ($state !== 'active' && $state !== 'activating' && $enabled === 'enabled')) {
                        $problems[] = "{$unit} is {$state}";
                    }

                    break;
                case 'config':
                    $lines[] = "config {$parts[1]}: {$parts[2]}";

                    if (($parts[2] ?? '') === 'fail') {
                        $problems[] = "the {$parts[1]} configuration fails its test";
                    }

                    break;
                case 'http':
                    $code = (int) ($parts[1] ?? 0);
                    $lines[] = "http probe: {$code}";

                    if ($code === 0 || $code >= 500) {
                        $problems[] = "the local HTTP probe answered {$code}";
                    }

                    break;
            }
        }

        if ($lines === []) {
            $problems[] = 'the probe returned nothing readable';
        }

        return ['healthy' => $problems === [], 'problems' => $problems, 'report' => implode("\n", $lines), 'disk' => $disk];
    }

    public function recordDisk(Machine $machine, ?float $disk): void
    {
        if ($disk !== null) {
            MachineMetric::create(['machine_id' => $machine->id, 'name' => 'disk_root_percent', 'value' => $disk, 'recorded_at' => now()]);
            MachineMetric::where('machine_id', $machine->id)->where('recorded_at', '<', now()->subDays(60))->delete();
        }
    }

    private function units(): string
    {
        return self::UNITS;
    }
}
