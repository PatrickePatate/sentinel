<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\On;

/** Re-renders the component when the server pushes "something changed" over WebSockets (a no-op without Echo). */
trait ListensToRealtime
{
    #[On('echo-private:sentinel,.Updated')]
    public function realtimeRefresh(): void {}
}
