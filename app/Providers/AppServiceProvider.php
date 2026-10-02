<?php

namespace App\Providers;

use App\Models\User;
use App\Ssh\ActionCatalog;
use App\Ssh\Fake\FakeTransport;
use App\Ssh\PhpseclibTransport;
use App\Ssh\SshTransport;
use App\Ssh\ToolCatalog;
use App\Support\Realtime;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolCatalog::class, fn () => ToolCatalog::default());
        $this->app->singleton(ActionCatalog::class, fn () => ActionCatalog::default());
        $this->app->bind(SshTransport::class, fn () => config('sentinel.transport') === 'fake' ? new FakeTransport : new PhpseclibTransport);
    }

    public function boot(): void
    {
        // @realtime ... @else ... @endrealtime: markup that depends on whether the dashboard gets WebSocket pushes.
        Blade::if('realtime', fn () => Realtime::enabled());

        if (config('sentinel.transport') === 'fake' && $this->app->isProduction()) {
            throw new RuntimeException('SENTINEL_TRANSPORT=fake is forbidden in production: it would report a simulated machine as real.');
        }

        // Viewers see the dashboard, approvers also act on it (actions, scans, chat, issues), admins configure it.
        Gate::define('view', fn (User $user) => $user->hasRole('viewer'));
        Gate::define('approve', fn (User $user) => $user->hasRole('approver'));
        Gate::define('admin', fn (User $user) => $user->hasRole('admin'));
    }
}
