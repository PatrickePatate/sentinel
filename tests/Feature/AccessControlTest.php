<?php

use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('drops the "approve always" grants on revoke, so lifting the revocation does not bring them back', function () {
    $machine = Machine::factory()->create(['autonomy_enabled' => true, 'trusted_actions' => ['start_crashed_service']]);
    $access = app(AccessControl::class);

    $access->revoke($machine);
    $access->lift($machine->fresh());

    expect($machine->fresh()->trusts('start_crashed_service'))->toBeFalse()
        ->and((bool) $machine->fresh()->autonomy_enabled)->toBeFalse();
});

it('closes approved actions left running by a worker that died', function () {
    $machine = Machine::factory()->create();
    $attrs = ['machine_id' => $machine->id, 'action' => 'clean_apt_cache', 'arguments' => [], 'command' => 'apt-get clean', 'risk' => 'low', 'reason' => 'x', 'status' => 'running'];
    $stuck = PendingAction::create($attrs);
    $stuck->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
    $live = PendingAction::create($attrs);

    Artisan::call('schedule:test', ['--name' => 'close-stale-actions']);

    expect($stuck->fresh()->status)->toBe('failed')->and($live->fresh()->status)->toBe('running');
});
