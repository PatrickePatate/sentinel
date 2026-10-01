<?php

namespace App\Ai\Tools;

use App\Models\Machine;
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

        return $out.$this->diskTrend();
    }

    private function diskTrend(): string
    {
        $trend = $this->machine->diskTrend();

        if ($trend === null) {
            return "Disk trend: not enough samples yet (Sentinel records one at each scheduled web server check).\n";
        }

        $line = sprintf('Disk trend: / was %.0f%% %s, is %.0f%% now (%+.1f points per day)', $trend['from'], $trend['since']->diffForHumans(), $trend['percent'], $trend['per_day']);

        return $line.($trend['days_left'] !== null ? "; full in about {$trend['days_left']} days at this pace" : '').".\n";
    }
}
