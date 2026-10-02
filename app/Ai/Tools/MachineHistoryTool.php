<?php

namespace App\Ai\Tools;

use App\Models\Machine;
use App\Monitoring\MetricsCollector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Spatie\Activitylog\Models\Activity;
use Stringable;

/** What Sentinel itself recorded about this machine: repairs it ran lately (spot a service that keeps crashing) and the disk trend. */
class MachineHistoryTool implements Tool
{
    public function __construct(private Machine $machine) {}

    public function name(): string
    {
        return 'machine_history';
    }

    public function description(): Stringable|string
    {
        return 'Sentinel\'s own records for this machine (no SSH): corrective actions executed in the last 7 days, grouped by command (a count above 2 means a service keeps failing: look for the root cause instead of repeating the fix), and the root filesystem usage trend.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        $runs = Activity::query()
            ->where('log_name', 'ssh')->whereIn('event', ['action_executed', 'action_approved'])
            ->where('subject_type', $this->machine->getMorphClass())->where('subject_id', $this->machine->getKey())
            ->where('created_at', '>=', now()->subDays(7))
            ->get()
            ->groupBy(fn (Activity $a) => $a->properties['command'] ?? $a->description)
            ->map(fn ($group, $command) => sprintf('%dx  %s  (last %s)', $group->count(), $command, $group->max('created_at')->diffForHumans()))
            ->values();

        $out = $runs->isEmpty() ? "No corrective action ran on this machine in the last 7 days.\n" : "Corrective actions in the last 7 days:\n".$runs->implode("\n")."\n";

        return $out.$this->metrics();
    }

    private function metrics(): string
    {
        $latest = $this->machine->latestMetrics();

        if ($latest === []) {
            return "Metrics: none recorded in the last 24 hours.\n";
        }

        $lines = [];

        foreach (MetricsCollector::METRICS as $name => [$label, $unit]) {
            if (! isset($latest[$name])) {
                continue;
            }

            $line = "{$label}: {$latest[$name]}{$unit}";
            $trend = $unit === '%' ? $this->machine->metricTrend($name) : null;

            if ($trend) {
                $line .= sprintf(' (%+.1f points per day over 7 days%s)', $trend['per_day'], $trend['days_left'] !== null ? ", full in about {$trend['days_left']} days" : '');
            }

            $lines[] = $line;
        }

        return "Metrics (latest sample, read by Sentinel):\n".implode("\n", $lines)."\n";
    }
}
