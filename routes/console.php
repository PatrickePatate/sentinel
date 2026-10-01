<?php

use App\Models\AgentRun;
use App\Models\PendingAction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sentinel:scan-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('sentinel:check-sites')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// An approved action whose worker died mid-run would stay "running" forever: close it, the audit log has the rest.
Schedule::call(fn () => PendingAction::where('status', 'running')->where('updated_at', '<', now()->subMinutes(30))
    ->update(['status' => 'failed', 'output' => 'The worker stopped before the action finished: check the machine and the audit log.', 'decided_at' => now()]))
    ->everyTenMinutes()->name('close-stale-actions')->onOneServer();

// Plain-code checks that found nothing are not worth keeping for long.
Schedule::call(fn () => AgentRun::where('provider', 'precheck')->where('created_at', '<', now()->subDays(3))->delete())->daily()->name('prune-prechecks');

// Prices change rarely: a weekly look keeps the run cost estimates honest.
Schedule::command('sentinel:pricing')->weekly()->onOneServer();
