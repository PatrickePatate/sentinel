<?php

namespace App\Livewire\Scans;

use App\Livewire\Concerns\AuthorizesAccess;
use App\Livewire\Concerns\ListensToRealtime;
use App\Models\AgentRun;
use App\Models\Machine;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Scans'])]
class Index extends Component
{
    use AuthorizesAccess, ListensToRealtime, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $machine = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $runs = AgentRun::with('machine')->whereNull('parent_run_id')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->machine !== '', fn ($q) => $q->where('machine_id', $this->machine))
            ->latest('id')->paginate(20);

        return view('livewire.scans.index', [
            'runs' => $runs,
            'machines' => Machine::orderBy('name')->pluck('name', 'id'),
            'live' => $runs->contains(fn (AgentRun $r) => $r->isActive()),
        ]);
    }
}
