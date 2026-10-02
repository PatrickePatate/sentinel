<?php

use App\Http\Controllers\ProvisionController;
use App\Http\Controllers\TelegramWebhookController;
use App\Livewire\Actions;
use App\Livewire\AuditLog;
use App\Livewire\Auth\Login;
use App\Livewire\Channels;
use App\Livewire\Dashboard;
use App\Livewire\Findings;
use App\Livewire\Machines;
use App\Livewire\ScanReport;
use App\Livewire\Scans;
use App\Livewire\Sites;
use App\Models\Machine;
use App\Ssh\AuditTrail;
use App\Ssh\Provisioning\ProvisionScript;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/login', Login::class)->middleware('guest')->name('login');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// The dashboard: administrators only (the Livewire update endpoint re-checks it in every component).
Route::middleware(['auth', 'can:admin'])->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');

    Route::get('/machines', Machines\Index::class)->name('machines.index');
    Route::get('/machines/create', Machines\Form::class)->name('machines.create');
    Route::get('/machines/{machine}', Machines\Show::class)->name('machines.show');
    Route::get('/machines/{machine}/edit', Machines\Form::class)->name('machines.edit');
    Route::get('/machines/{machine}/provision.sh', function (Machine $machine, ProvisionScript $script, AuditTrail $audit) {
        $audit->record($machine, null, 'provision_script_downloaded', 'provision script downloaded');

        return response($script->render($machine), 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="sentinel-provision-'.Str::slug($machine->name).'.sh"',
        ]);
    })->name('machines.provision-script');

    Route::get('/sites', Sites\Index::class)->name('sites.index');
    Route::get('/scans', Scans\Index::class)->name('scans.index');
    Route::get('/scans/{run}', ScanReport::class)->name('scans.show');

    Route::get('/issues', Findings\Index::class)->name('findings.index');
    Route::get('/actions', Actions\Index::class)->name('actions.index');

    Route::get('/channels', Channels\Index::class)->name('channels.index');
    Route::get('/channels/create', Channels\Form::class)->name('channels.create');
    Route::get('/channels/{channel}/edit', Channels\Form::class)->name('channels.edit');

    Route::get('/audit', AuditLog::class)->name('audit');
});

// Telegram button presses. Not behind the session auth or CSRF: authenticated by the per-channel secret header instead.
Route::post('/telegram/webhook/{channel}', TelegramWebhookController::class)
    ->withoutMiddleware([PreventRequestForgery::class])
    ->middleware('throttle:60,1')
    ->name('sentinel.telegram.webhook');

// One-line provisioning, called by the machine itself: authenticated by the short-lived token in the URL.
Route::middleware('throttle:20,1')->prefix('provision/{machine}/{token}')->group(function () {
    Route::get('/', [ProvisionController::class, 'script'])->name('sentinel.provision.script');
    Route::post('/callback', [ProvisionController::class, 'callback'])
        ->withoutMiddleware([PreventRequestForgery::class])
        ->name('sentinel.provision.callback');
});
