<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Livewire's update endpoint is global, so every component re-checks on every request that the caller may see it,
 * whatever route middleware the page was served with. Methods that change something check their own ability on top.
 */
trait AuthorizesAccess
{
    public function bootAuthorizesAccess(): void
    {
        // The Livewire endpoint skips the route middleware: two-factor authentication is enforced here as well.
        abort_if(config('sentinel.auth.require_two_factor') && ! auth()->user()?->hasTwoFactor(), 403, 'Set up two-factor authentication first.');
        Gate::authorize($this->requiredAbility());
    }

    /** 'view' by default; a component for administrators only overrides it. */
    protected function requiredAbility(): string
    {
        return 'view';
    }

    /** Throws a 403 unless the user's role allows $ability ('approve' or 'admin'). */
    protected function allow(string $ability): void
    {
        Gate::authorize($ability);
    }
}
