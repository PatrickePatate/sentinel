<?php

namespace App\Livewire\Actions;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\ListensToRealtime;
use App\Models\PendingAction;
use App\Ssh\ActionCatalog;
use App\Ssh\ActionExecutor;
use App\Ssh\Actions\HasSafeguards;
use App\Ssh\Actions\RiskLevel;
use App\Ssh\AuditTrail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('components.layouts.app', ['title' => 'Pending actions'])]
class Index extends Component
{
    use AuthorizesAdmin, ListensToRealtime, WithPagination;

    #[Url]
    public string $status = 'pending';

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function approve(int $id, bool $always = false): void
    {
        $executor = app(ActionExecutor::class);
        $pending = PendingAction::whereKey($id)->where('status', 'pending')->first();

        if (! $pending) {
            $this->dispatch('toast', message: 'This action was already decided', type: 'warning');

            return;
        }

        try {
            $executor->approve($pending);
            $pending->refresh();

            if ($always && $pending->status === 'executed') {
                $this->trust($pending);
            }
            $this->dispatch('toast', message: $pending->status === 'executed' ? 'Action executed' : 'Action finished: '.$pending->status, type: $pending->status === 'executed' ? 'success' : 'warning');
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'Could not run the action', description: $e->getMessage(), type: 'error');
        }
    }

    /** An explicit grant, only for actions whose root wrapper enforces its own safeguards, and revocable on the machine page. */
    private function trust(PendingAction $pending): void
    {
        $action = app(ActionCatalog::class)->get($pending->action);

        if (! $action instanceof HasSafeguards || $action->risk() === RiskLevel::High) {
            return;
        }

        $machine = $pending->machine;
        $machine->forceFill(['trusted_actions' => array_values(array_unique([...($machine->trusted_actions ?? []), $pending->action]))])->save();
        app(AuditTrail::class)->record($machine, $pending->agentRun, 'action_trusted', $pending->action, ['pending_action_id' => $pending->id]);
        $this->dispatch('toast', message: "{$pending->action} is now allowed on {$machine->name} without asking", description: 'Revoke it on the machine page.', type: 'success');
    }

    public function reject(int $id, ActionExecutor $executor): void
    {
        if ($pending = PendingAction::whereKey($id)->where('status', 'pending')->first()) {
            $executor->reject($pending);
            $this->dispatch('toast', message: 'Action rejected');
        }
    }

    public function render()
    {
        return view('livewire.actions.index', [
            'actions' => PendingAction::with('machine')
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->latest('id')->paginate(20),
            'trustable' => collect(app(ActionCatalog::class)->all())->filter(fn ($a) => $a instanceof HasSafeguards && $a->risk() !== RiskLevel::High)->keys()->all(),
            'ranBefore' => PendingAction::where('status', 'executed')->selectRaw('machine_id, action, count(*) as n')->groupBy('machine_id', 'action')->get()->mapWithKeys(fn ($r) => ["{$r->machine_id}:{$r->action}" => $r->n]),
            'counts' => PendingAction::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }
}
