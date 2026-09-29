<?php

namespace App\Providers;

use App\Ssh\ActionCatalog;
use App\Ssh\PhpseclibTransport;
use App\Ssh\SshTransport;
use App\Ssh\ToolCatalog;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolCatalog::class, fn () => ToolCatalog::default());
        $this->app->singleton(ActionCatalog::class, fn () => ActionCatalog::default());
        $this->app->bind(SshTransport::class, PhpseclibTransport::class);
    }

    public function boot(): void
    {
        //
    }
}
