<?php

namespace App\Livewire;

use App\Ai\Budget;
use App\Livewire\Concerns\AuthorizesAccess;
use App\Models\AgentRun;
use App\Models\Machine;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** What the model calls cost, per month: by machine, by kind of run and by model, against the budgets. */
#[Layout('components.layouts.app', ['title' => 'Costs'])]
class Costs extends Component
{
    use AuthorizesAccess;

    /** Y-m */
    #[Url]
    public string $month = '';

    public function render(Budget $budget)
    {
        $months = collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => now()->startOfMonth()->subMonths($i)->format('F Y')]);
        $month = $months->has($this->month) ? Carbon::createFromFormat('Y-m', $this->month)->startOfMonth() : now()->startOfMonth();
        $current = $month->isSameMonth(now());

        $runs = AgentRun::whereBetween('created_at', [$month->copy(), $month->copy()->endOfMonth()]);
        $paid = (clone $runs)->whereNotNull('cost_usd');

        $kinds = (clone $paid)->select('trigger', 'profile')->selectRaw('count(*) as runs, sum(cost_usd) as cost')->groupBy('trigger', 'profile')->toBase()->get()
            ->map(fn ($row) => ['label' => $this->kind($row->trigger, $row->profile), 'runs' => (int) $row->runs, 'cost' => (float) $row->cost])
            ->groupBy('label')->map(fn ($rows, $label) => ['label' => $label, 'runs' => $rows->sum('runs'), 'cost' => $rows->sum('cost')])
            ->sortByDesc('cost')->values();

        $machines = Machine::orderBy('name')->get()->keyBy('id');
        $byMachine = (clone $paid)->selectRaw('machine_id, count(*) as runs, sum(cost_usd) as cost')->groupBy('machine_id')->toBase()->get()
            ->map(fn ($row) => ['machine' => $machines->get($row->machine_id), 'runs' => (int) $row->runs, 'cost' => (float) $row->cost])
            ->filter(fn (array $row) => $row['machine'] !== null)->sortByDesc('cost')->values();

        $daily = (clone $paid)->selectRaw('date(created_at) as day, sum(cost_usd) as cost')->groupBy('day')->pluck('cost', 'day');
        $days = collect(range(1, $month->daysInMonth))->map(fn ($d) => [
            'day' => $month->copy()->day($d),
            'cost' => (float) ($daily[$month->copy()->day($d)->toDateString()] ?? 0),
        ]);

        return view('livewire.costs', [
            'months' => $months,
            'selected' => $month->format('Y-m'),
            'current' => $current,
            'total' => (float) (clone $paid)->sum('cost_usd'),
            'limit' => $budget->limit(),
            'projection' => $current ? $budget->projection() : null,
            'tokens' => (int) (clone $paid)->sum('input_tokens') + (int) (clone $paid)->sum('output_tokens'),
            'avoided' => (clone $runs)->whereIn('provider', ['precheck', 'unchanged'])->count(),
            'unpriced' => (clone $runs)->whereNull('cost_usd')->whereNotNull('input_tokens')->count(),
            'kinds' => $kinds,
            'byMachine' => $byMachine,
            'byModel' => (clone $paid)->selectRaw("coalesce(model, '(provider default)') as name, count(*) as runs, sum(cost_usd) as cost")->groupBy('name')->orderByDesc('cost')->toBase()->get(),
            'days' => $days,
            'top' => (clone $paid)->with('machine')->orderByDesc('cost_usd')->limit(10)->get(),
        ]);
    }

    private function kind(?string $trigger, ?string $profile): string
    {
        return match ($trigger) {
            'chat' => 'Chat',
            'follow_up' => 'Follow-ups on scans',
            'escalation' => 'Escalation checks (main model)',
            'site_down' => 'Site down analyses',
            'scheduled' => 'Scheduled '.strtolower(config("sentinel.scheduling.profiles.{$profile}.label") ?? (string) $profile),
            default => 'Manual '.strtolower(config("sentinel.scheduling.profiles.{$profile}.label") ?? 'scan'),
        };
    }
}
