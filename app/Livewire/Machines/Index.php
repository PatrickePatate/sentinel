<?php

namespace App\Livewire\Machines;

use App\Ai\ScanRunner;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\ListensToRealtime;
use App\Livewire\Concerns\StartsScans;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Ssh\Provisioning\ClientUpdater;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('components.layouts.app', ['title' => 'Machines'])]
class Index extends Component
{
    use AuthorizesAdmin;
    use ListensToRealtime;
    use StartsScans;

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $scanMachineId = null;

    public function startScan(int $machineId): void
    {
        $this->scanMachineId = $machineId;
        $this->resetScan();
        $this->dispatch('open-modal', 'scan');
    }

    protected function scanTarget(): ?Machine
    {
        return $this->scanMachineId ? Machine::find($this->scanMachineId) : null;
    }

    public function scan(ScanRunner $runner)
    {
        return $this->queueScan($runner, Machine::findOrFail($this->scanMachineId));
    }

    /** Pushes the current client bundle to every machine that runs an older one. */
    public function updateOutdated(ClientUpdater $updater): void
    {
        $updated = 0;
        $failed = [];

        foreach (Machine::whereNull('revoked_at')->whereNotNull('host_key_fingerprint')->get() as $machine) {
            try {
                if ($updater->check($machine)->canUpdateRemotely()) {
                    $updater->deploy($machine);
                    $updated++;
                }
            } catch (Throwable $e) {
                $failed[] = $machine->name;
            }
        }

        $this->dispatch('toast', message: "{$updated} machine(s) updated", description: $failed ? 'Failed: '.implode(', ', $failed) : '', type: $failed ? 'warning' : 'success');
    }

    public function render(ClientUpdater $updater)
    {
        $machines = Machine::query()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('host', 'like', "%{$this->search}%"))
            ->orderBy('name')->get();

        $severities = AgentRun::whereNull('parent_run_id')->where('status', 'completed')->whereNotNull('severity')
            ->whereIn('machine_id', $machines->pluck('id'))->latest('id')->get()->unique('machine_id')->keyBy('machine_id');

        $active = AgentRun::whereIn('status', ['queued', 'running'])->pluck('machine_id')->flip();

        $clients = $machines->mapWithKeys(fn (Machine $m) => [$m->id => $updater->lastKnown($m)]);

        return view('livewire.machines.index', [
            'machines' => $machines,
            'severities' => $severities,
            'active' => $active,
            'clients' => $clients,
            'outdated' => $clients->filter(fn ($c, $id) => $c->canUpdateRemotely())->count(),
        ]);
    }
}
