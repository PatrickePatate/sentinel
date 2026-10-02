<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesAccess;
use App\Livewire\Concerns\ListensToRealtime;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

#[Layout('components.layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    use AuthorizesAccess;
    use ListensToRealtime;

    public function render()
    {
        $machines = Machine::query()->orderBy('name')->get();
        $latest = AgentRun::whereNull('parent_run_id')->where('status', 'completed')->whereNotNull('severity')
            ->latest('id')->get()->unique('machine_id')->keyBy('machine_id');

        return view('livewire.dashboard', [
            'machines' => $machines,
            'latest' => $latest,
            'unpinned' => $machines->whereNull('host_key_fingerprint')->whereNull('revoked_at')->count(),
            'activeRuns' => AgentRun::with('machine')->whereIn('status', ['queued', 'running'])->latest('id')->get(),
            'pending' => PendingAction::with('machine')->where('status', 'pending')->latest('id')->limit(5)->get(),
            'pendingCount' => PendingAction::where('status', 'pending')->count(),
            'recentRuns' => AgentRun::with('machine')->whereNull('parent_run_id')->latest('id')->limit(6)->get(),
            'activity' => Activity::where('log_name', 'ssh')->with('subject')->latest('id')->limit(12)->get(),
            'attention' => $latest->filter(fn (AgentRun $r) => in_array($r->severity, ['high', 'critical']))->count(),
        ]);
    }
}
