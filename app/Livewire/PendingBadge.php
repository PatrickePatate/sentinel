<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesAccess;
use App\Livewire\Concerns\ListensToRealtime;
use App\Models\PendingAction;
use Livewire\Component;

/** Sidebar counter of actions waiting for a human. Polls, and tells the admin when a new one shows up. */
class PendingBadge extends Component
{
    use AuthorizesAccess;
    use ListensToRealtime;

    public ?int $known = null;

    public function render()
    {
        $count = PendingAction::where('status', 'pending')->count();

        if ($this->known !== null && $count > $this->known) {
            $this->dispatch('toast', message: 'An action is waiting for your approval', type: 'warning');
        }

        $this->known = $count;

        return view('livewire.pending-badge', ['count' => $count]);
    }
}
