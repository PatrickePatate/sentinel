<?php

namespace App\Monitoring;

use App\Models\AgentRun;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\MachineMetric;
use App\Models\PendingAction;
use Carbon\CarbonInterface;
use Spatie\Activitylog\Models\Activity;

/** What happened on the fleet over a period: the content of the weekly digest. */
class FleetDigest
{
    /**
     * @return array{since: CarbonInterface, scans: int, cost: float, actions: int, pending: int, open: array<string, int>,
     *     opened: int, resolved: int, machines: list<array{name: string, lines: list<string>}>}
     */
    public function build(CarbonInterface $since): array
    {
        $machines = [];

        foreach (Machine::whereNull('revoked_at')->orderBy('name')->get() as $machine) {
            $lines = [];
            $open = $machine->findings()->unresolved()->get();
            $opened = $machine->findings()->where('first_seen_at', '>=', $since)->count();
            $resolved = $machine->findings()->where('status', 'resolved')->where('resolved_at', '>=', $since)->count();

            if ($open->isNotEmpty()) {
                $worst = $open->sortByDesc(fn (Finding $f) => $f->severityLevel()->rank())->first();
                $lines[] = "{$open->count()} open issue(s), worst: {$worst->title} ({$worst->severity})";
            }

            if ($opened || $resolved) {
                $lines[] = "{$opened} new, {$resolved} resolved this week";
            }

            $disk = $machine->diskTrend();

            if ($disk && ($disk['percent'] >= 80 || ($disk['days_left'] !== null && $disk['days_left'] <= 30))) {
                $lines[] = sprintf('Root filesystem %d%%', round($disk['percent'])).($disk['days_left'] !== null ? ", full in about {$disk['days_left']} days" : '');
            }

            $latest = MachineMetric::where('machine_id', $machine->id)->where('recorded_at', '>=', now()->subDay())->orderBy('recorded_at')->pluck('value', 'name');

            if (($latest['security_updates_pending'] ?? 0) > 0) {
                $lines[] = (int) $latest['security_updates_pending'].' security update(s) pending';
            }

            if (($latest['reboot_required'] ?? 0) > 0) {
                $lines[] = 'Reboot required';
            }

            if ($lines !== []) {
                $machines[] = ['name' => $machine->name, 'lines' => $lines];
            }
        }

        $runs = AgentRun::where('created_at', '>=', $since);

        return [
            'since' => $since,
            'scans' => (clone $runs)->whereNull('parent_run_id')->where('trigger', '!=', 'chat')->count(),
            'cost' => (float) (clone $runs)->sum('cost_usd'),
            'actions' => PendingAction::where('status', 'executed')->where('decided_at', '>=', $since)->count()
                + Activity::where('log_name', 'ssh')->where('event', 'action_executed')->where('created_at', '>=', $since)->count(),
            'pending' => PendingAction::where('status', 'pending')->count(),
            'open' => Finding::unresolved()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->map(fn ($n) => (int) $n)->all(),
            'opened' => Finding::where('first_seen_at', '>=', $since)->count(),
            'resolved' => Finding::where('status', 'resolved')->where('resolved_at', '>=', $since)->count(),
            'machines' => $machines,
        ];
    }
}
