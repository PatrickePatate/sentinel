<?php

namespace App\Providers;

use App\Ssh\ActionCatalog;
use App\Ssh\Fake\FakeTransport;
use App\Ssh\PhpseclibTransport;
use App\Ssh\SshTransport;
use App\Ssh\ToolCatalog;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(SharpServiceProvider::class);
        $this->app->singleton(ToolCatalog::class, fn () => ToolCatalog::default());
        $this->app->singleton(ActionCatalog::class, fn () => ActionCatalog::default());
        $this->app->bind(SshTransport::class, fn () => config('sentinel.transport') === 'fake' ? new FakeTransport : new PhpseclibTransport);
    }

    public function boot(): void
    {
        if (config('sentinel.transport') === 'fake' && $this->app->isProduction()) {
            throw new RuntimeException('SENTINEL_TRANSPORT=fake is forbidden in production: it would report a simulated machine as real.');
        }

        Livewire::setUpdateRoute(fn ($handle) => Route::post('/chat/livewire/update', $handle)
            ->middleware(['sharp_common', 'sharp_auth'])
            ->name('sentinel.livewire.update'));
    }
}
