<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\ListensToRealtime;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

#[Layout('components.layouts.app', ['title' => 'Audit log'])]
class AuditLog extends Component
{
    use AuthorizesAdmin, ListensToRealtime, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $live = true;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $entries = Activity::where('log_name', 'ssh')->with('subject')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', "%{$this->search}%")->orWhere('event', 'like', "%{$this->search}%")))
            ->latest('id')->paginate(40);

        return view('livewire.audit-log', ['entries' => $entries]);
    }
}
