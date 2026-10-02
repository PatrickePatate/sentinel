<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\ListensToRealtime;
use App\Models\AgentRun;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\MachineMetric;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Every machine side by side: open issues, latest health numbers and pending updates, worst first. */
#[Layout('components.layouts.app', ['title' => 'Fleet'])]
class Fleet extends Component
{
    use AuthorizesAdmin, ListensToRealtime;

    public const FILTERS = ['' => 'All machines', 'issues' => 'With open issues', 'security' => 'Security updates pending', 'reboot' => 'Reboot required', 'disk' => 'Disk over 80%'];

    #[Url]
    public string $filter = '';

    public function render()
    {
        $machines = Machine::whereNull('revoked_at')->orderBy('name')->get();
        $ids = $machines->pluck('id');

        $issues = Finding::unresolved()->whereIn('machine_id', $ids)->selectRaw('machine_id, severity, count(*) as n')
            ->groupBy('machine_id', 'severity')->get()->groupBy('machine_id')
            ->map(fn ($rows) => $rows->pluck('n', 'severity'));

        // Latest value of each metric per machine, from the last day only: an old number is worse than none.
        $metrics = MachineMetric::whereIn('machine_id', $ids)->where('recorded_at', '>=', now()->subDay())
            ->orderBy('recorded_at')->get()->groupBy('machine_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (MachineMetric $m) => [$m->name => $m->value]));

        $lastScans = AgentRun::whereIn('machine_id', $ids)->whereNull('parent_run_id')->where('status', 'completed')
            ->selectRaw('machine_id, max(created_at) as at')->groupBy('machine_id')->pluck('at', 'machine_id');

        $rows = $machines->map(fn (Machine $machine) => [
            'machine' => $machine,
            'issues' => $issues->get($machine->id, collect()),
            'metrics' => $metrics->get($machine->id, collect()),
            'disk' => $machine->diskTrend(),
            'last_scan' => $lastScans->get($machine->id),
        ]);

        $rows = $rows->filter(fn (array $row) => match ($this->filter) {
            'issues' => $row['issues']->sum() > 0,
            'security' => ($row['metrics']['security_updates_pending'] ?? 0) > 0,
            'reboot' => ($row['metrics']['reboot_required'] ?? 0) > 0,
            'disk' => ($row['metrics']['disk_root_percent'] ?? 0) > 80,
            default => true,
        })->sortByDesc(fn (array $row) => [
            (int) ($row['issues']['critical'] ?? 0), (int) ($row['issues']['high'] ?? 0), (int) ($row['issues']['medium'] ?? 0), (float) ($row['metrics']['disk_root_percent'] ?? 0),
        ])->values();

        return view('livewire.fleet', [
            'rows' => $rows,
            'totals' => [
                'machines' => $machines->count(),
                'serious' => Finding::unresolved()->whereIn('machine_id', $ids)->whereIn('severity', ['high', 'critical'])->count(),
                'security' => $metrics->sum(fn ($m) => $m['security_updates_pending'] ?? 0),
                'reboot' => $metrics->filter(fn ($m) => ($m['reboot_required'] ?? 0) > 0)->count(),
            ],
        ]);
    }
}
