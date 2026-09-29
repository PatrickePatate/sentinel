<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\AllowFramingFromSelfOnly;
use App\Livewire\MachineChat;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * The chat is embedded in a Sharp show page through an iframe, so it must be
 * protected by Sharp's own authentication (session + gate viewSharp), not Laravel's.
 * Livewire's update endpoint is protected the same way (see AppServiceProvider).
 */
Route::middleware(['sharp_common', 'sharp_auth', AllowFramingFromSelfOnly::class])
    ->get('/chat/{machine}', MachineChat::class)
    ->name('sentinel.chat');

// Telegram button presses. Not behind Sharp auth or CSRF: authenticated by the per-channel secret header instead.
Route::post('/telegram/webhook/{channel}', TelegramWebhookController::class)
    ->withoutMiddleware([PreventRequestForgery::class])
    ->middleware('throttle:60,1')
    ->name('sentinel.telegram.webhook');
