<?php

namespace App\Providers;

use App\Models\User;
use App\Sharp\SharpMenu;
use Code16\Sharp\Config\SharpConfigBuilder;
use Code16\Sharp\SharpAppServiceProvider;
use Illuminate\Support\Facades\Gate;

class SharpServiceProvider extends SharpAppServiceProvider
{
    protected function configureSharp(SharpConfigBuilder $config): void
    {
        $config
            ->setName('Sentinel')
            ->enableLoginRateLimiting(5)
            ->discoverEntities()
            ->setSharpMenu(SharpMenu::class);
    }

    protected function declareAccessGate(): void
    {
        // Gates the back-office AND the chat / Livewire endpoint (both behind sharp_auth).
        Gate::define('viewSharp', fn (User $user) => $user->is_admin);
    }
}
