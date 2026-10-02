<?php

use App\Jobs\RunScan;
use App\Livewire\Actions\Index as ActionsIndex;
use App\Livewire\Machines\Form;
use App\Livewire\Machines\Show;
use App\Models\Machine;
use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Models\User;
use App\Notifications\ActionApprovalNotification;
use App\Notifications\MachineAlertNotification;
use App\Notifications\Notifier;
use App\Ssh\AccessControl;
use App\Ssh\ActionExecutor;
use App\Ssh\CommandResult;
use App\Ssh\SshTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function windowTransport(): object
{
    $transport = new class implements SshTransport
    {
        public array $commands = [];

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult(str_contains($command, 'is-active') ? 'active' : 'done', 0);
        }
    };
    app()->instance(SshTransport::class, $transport);

    return $transport;
}

/** Sunday 03:00 for two hours. */
function windowed(array $attributes = []): Machine
{
    return Machine::factory()->create($attributes + ['maintenance_days' => [7], 'maintenance_start' => '03:00', 'maintenance_minutes' => 120]);
}

function heldRestart(Machine $machine): PendingAction
{
    app(ActionExecutor::class)->propose($machine, 'restart_service', ['service' => 'nginx'], null, 'test');

    return PendingAction::latest('id')->first();
}

beforeEach(function () {
    Notification::fake();
    config(['sentinel.actions.restartable_services' => ['nginx']]);
    NotificationChannel::create(['name' => 'ops', 'type' => 'mail', 'settings' => ['email' => 'ops@example.com'], 'min_severity' => 'low']);
});

it('finds the window in progress or the next one, including one that crosses midnight', function () {
    $machine = windowed();

    $this->travelTo(Carbon::parse('2026-10-07 12:00')); // Wednesday
    expect($machine->maintenanceWindow()[0]->toDateTimeString())->toBe('2026-10-11 03:00:00')->and($machine->inMaintenanceWindow())->toBeFalse();

    $this->travelTo(Carbon::parse('2026-10-11 04:30')); // Sunday, inside
    expect($machine->inMaintenanceWindow())->toBeTrue()->and($machine->maintenanceWindow()[1]->toDateTimeString())->toBe('2026-10-11 05:00:00');

    $late = windowed(['maintenance_days' => [6], 'maintenance_start' => '23:00', 'maintenance_minutes' => 240]);
    $this->travelTo(Carbon::parse('2026-10-11 01:00')); // Sunday night, window opened Saturday 23:00
    expect($late->inMaintenanceWindow())->toBeTrue();

    expect(Machine::factory()->create()->maintenanceWindow())->toBeNull();
});

it('runs an action approved for the window only once the window opens', function () {
    $transport = windowTransport();
    $this->travelTo(Carbon::parse('2026-10-07 12:00'));
    $pending = heldRestart(windowed());

    app(ActionExecutor::class)->schedule($pending);
    expect($pending->fresh()->status)->toBe('scheduled')->and($pending->fresh()->run_after->toDateTimeString())->toBe('2026-10-11 03:00:00');

    $this->artisan('sentinel:run-scheduled-actions')->assertSuccessful();
    expect($transport->commands)->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-10-11 03:01'));
    $this->artisan('sentinel:run-scheduled-actions')->assertSuccessful();

    expect($pending->fresh()->status)->toBe('executed')
        ->and($transport->commands[0])->toBe("systemctl restart -- 'nginx' 2>&1")
        ->and(Activity::where('event', 'action_approved')->first()->properties['scheduled'])->toBeTrue();
});

it('does not let a scheduled action expire like an unanswered one', function () {
    windowTransport();
    $this->travelTo(Carbon::parse('2026-10-05 12:00'));
    $pending = heldRestart(windowed());
    app(ActionExecutor::class)->schedule($pending);

    $this->travelTo(Carbon::parse('2026-10-11 03:05'));
    app(ActionExecutor::class)->runScheduled();

    expect($pending->fresh()->status)->toBe('executed');
});

it('waits for the next window when one was missed', function () {
    $transport = windowTransport();
    $this->travelTo(Carbon::parse('2026-10-07 12:00'));
    $pending = heldRestart(windowed());
    app(ActionExecutor::class)->schedule($pending);

    $this->travelTo(Carbon::parse('2026-10-11 06:00')); // the window closed at 05:00
    app(ActionExecutor::class)->runScheduled();

    expect($transport->commands)->toBeEmpty()
        ->and($pending->fresh()->run_after->toDateTimeString())->toBe('2026-10-18 03:00:00');
});

it('refuses to run a scheduled action whose command changed since it was reviewed', function () {
    $transport = windowTransport();
    $this->travelTo(Carbon::parse('2026-10-07 12:00'));
    $pending = heldRestart(windowed());
    app(ActionExecutor::class)->schedule($pending);
    $pending->update(['arguments' => ['service' => 'other']]);
    config(['sentinel.actions.restartable_services' => ['nginx', 'other']]);

    $this->travelTo(Carbon::parse('2026-10-11 03:01'));
    app(ActionExecutor::class)->runScheduled();

    expect($pending->fresh()->status)->toBe('stale')->and($transport->commands)->toBeEmpty();
});

it('cannot schedule on a machine without a window', function () {
    windowTransport();
    $pending = heldRestart(Machine::factory()->create());

    app(ActionExecutor::class)->schedule($pending);
})->throws(HttpException::class);

it('cancels scheduled actions when access is revoked', function () {
    windowTransport();
    $machine = windowed();
    app(ActionExecutor::class)->schedule(heldRestart($machine));

    app(AccessControl::class)->revoke($machine);

    expect(PendingAction::sole()->status)->not->toBe('scheduled');
});

it('mutes alerts during planned work and the window, but never approvals or failed action checks', function () {
    $this->travelTo(Carbon::parse('2026-10-11 03:30'));
    $machine = windowed();

    app(Notifier::class)->machineAlert($machine, 'site down', 'x');
    app(Notifier::class)->machineAlert($machine, 'restart_service did not have the expected effect', 'x', evenWhenQuiet: true);
    windowTransport();
    heldRestart($machine);

    Notification::assertSentTimes(MachineAlertNotification::class, 1);
    Notification::assertSentTimes(ActionApprovalNotification::class, 1);
    expect(Activity::where('event', 'notification_suppressed')->first()->properties['reason'])->toBe('maintenance window');
});

it('skips scheduled scans during planned work', function () {
    Queue::fake();
    $busy = Machine::factory()->create(['scan_interval_minutes' => 60, 'host_key_fingerprint' => 'SHA256:x', 'maintenance_until' => now()->addHour()]);
    $free = Machine::factory()->create(['scan_interval_minutes' => 60, 'host_key_fingerprint' => 'SHA256:y']);

    $this->artisan('sentinel:scan-due')->assertSuccessful();

    Queue::assertPushed(RunScan::class, fn ($job) => $job->machineId === $free->id);
    Queue::assertNotPushed(RunScan::class, fn ($job) => $job->machineId === $busy->id);
});

it('lets an administrator set the window and start or end planned work', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();

    Livewire::test(Form::class, ['machine' => $machine])
        ->set('maintenance_days', [6, 7])->set('maintenance_start', '02:30')->set('maintenance_minutes', 60)
        ->call('save')->assertHasNoErrors();
    expect($machine->fresh()->maintenance_days)->toBe([6, 7])->and($machine->fresh()->hasMaintenanceWindow())->toBeTrue();

    Livewire::test(Form::class, ['machine' => $machine])->set('maintenance_start', '25:00')->call('save')->assertHasErrors('maintenance_start');

    Livewire::test(Show::class, ['machine' => $machine])->call('startPlannedWork', 4);
    expect($machine->fresh()->underPlannedWork())->toBeTrue();

    Livewire::test(Show::class, ['machine' => $machine])->call('endPlannedWork');
    expect($machine->fresh()->underPlannedWork())->toBeFalse()
        ->and(Activity::where('event', 'planned_work_started')->exists())->toBeTrue();
});

it('offers approve for the window on the actions page', function () {
    $this->actingAs(User::factory()->admin()->create());
    windowTransport();
    $pending = heldRestart(windowed());

    Livewire::test(ActionsIndex::class)->assertSee('Approve for the window')->call('scheduleForWindow', $pending->id);

    expect($pending->fresh()->status)->toBe('scheduled');
});
