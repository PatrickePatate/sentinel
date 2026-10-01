<?php

use App\Models\AgentRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sentinel:scan-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('sentinel:check-sites')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Plain-code checks that found nothing are not worth keeping for long.
Schedule::call(fn () => AgentRun::where('provider', 'precheck')->where('created_at', '<', now()->subDays(3))->delete())->daily()->name('prune-prechecks');
