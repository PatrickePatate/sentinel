<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Livewire's update endpoint is global, so every component re-checks on every request that the caller is an admin,
 * whatever route middleware the page was served with.
 */
trait AuthorizesAdmin
{
    public function bootAuthorizesAdmin(): void
    {
        Gate::authorize('admin');
    }
}
