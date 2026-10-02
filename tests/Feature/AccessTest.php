<?php

use App\Auth\Totp;
use App\Livewire\Actions\Index as ActionsIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Auth\TwoFactorSetup;
use App\Livewire\Findings\Index as FindingsIndex;
use App\Livewire\Machines\Show;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Models\User;
use App\Ssh\ActionExecutor;
use App\Ssh\CommandResult;
use App\Ssh\SshTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function accessTransport(): object
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

function heldAction(Machine $machine): PendingAction
{
    app(ActionExecutor::class)->propose($machine, 'restart_service', ['service' => 'nginx'], null, 'test');

    return PendingAction::latest('id')->first();
}

function withTwoFactor(User $user): User
{
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => ['aaaa-bbbb'], 'two_factor_confirmed_at' => now()])->save();

    return $user;
}

beforeEach(function () {
    Notification::fake();
    config(['sentinel.actions.restartable_services' => ['nginx']]);
});

// --- roles ---------------------------------------------------------------------------------------------------------

it('ranks roles: each one can do what the ones before it can', function () {
    $viewer = User::factory()->viewer()->make();
    $approver = User::factory()->approver()->make();
    $admin = User::factory()->admin()->make();

    expect([$viewer->can('view'), $viewer->can('approve'), $viewer->can('admin')])->toBe([true, false, false])
        ->and([$approver->can('view'), $approver->can('approve'), $approver->can('admin')])->toBe([true, true, false])
        ->and([$admin->can('view'), $admin->can('approve'), $admin->can('admin')])->toBe([true, true, true])
        ->and(User::factory()->make()->can('view'))->toBeFalse();
});

it('lets a viewer read every page but no admin page', function () {
    $this->actingAs(User::factory()->viewer()->create());
    $machine = Machine::factory()->create();

    foreach (['dashboard', 'fleet', 'machines.index', 'scans.index', 'findings.index', 'actions.index', 'audit'] as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('machines.show', $machine))->assertOk()->assertDontSee('Revoke access');
    $this->get(route('machines.edit', $machine))->assertForbidden();
    $this->get(route('channels.index'))->assertForbidden();
    $this->get(route('users.index'))->assertForbidden();
    $this->get(route('machines.provision-script', $machine))->assertForbidden();
});

it('refuses every change to a viewer, on the Livewire endpoint too', function () {
    $this->actingAs(User::factory()->viewer()->create());
    accessTransport();
    $pending = heldAction(Machine::factory()->create());

    Livewire::test(ActionsIndex::class)->assertDontSee('Approve and run')->call('approve', $pending->id)->assertForbidden();
    Livewire::test(Show::class, ['machine' => $pending->machine])->call('startPlannedWork', 1)->assertForbidden();
    Livewire::test(FindingsIndex::class)->call('resolve', 1)->assertForbidden();

    expect($pending->fresh()->status)->toBe('pending');
});

it('lets an approver decide actions but not configure machines or grant trust', function () {
    $this->actingAs(User::factory()->approver()->create());
    $transport = accessTransport();
    $machine = Machine::factory()->create();
    $pending = heldAction($machine);

    Livewire::test(ActionsIndex::class)->call('approve', $pending->id, true);

    expect($pending->fresh()->status)->toBe('executed')
        ->and($transport->commands)->not->toBeEmpty()
        ->and($machine->fresh()->trusted_actions)->toBeEmpty();

    Livewire::test(Show::class, ['machine' => $machine])->call('revoke')->assertForbidden();
    expect($machine->fresh()->isRevoked())->toBeFalse();
});

it('moves existing administrators to the admin role', function () {
    expect(Schema::hasColumn('users', 'is_admin'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'role'))->toBeTrue();
});

it('lets an administrator change roles and remove users, but not their own account', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->viewer()->create();
    $this->actingAs($admin);

    Livewire::test(UsersIndex::class)->call('setRole', $other->id, 'approver');
    expect($other->fresh()->role)->toBe('approver');

    Livewire::test(UsersIndex::class)->call('setRole', $other->id, 'root')->assertHasErrors('role');
    Livewire::test(UsersIndex::class)->call('setRole', $admin->id, 'viewer')->assertStatus(422);

    Livewire::test(UsersIndex::class)->call('remove', $other->id);
    expect(User::find($other->id))->toBeNull();
});

it('creates users with a role from the command line', function () {
    $this->artisan('sentinel:admin', ['email' => 'ops@example.org', '--role' => 'approver'])
        ->expectsQuestion('Password (12+ characters, mixed case, digits)', 'Correct-Horse-Battery-42-xyz')
        ->assertSuccessful();

    expect(User::firstWhere('email', 'ops@example.org')->role)->toBe('approver');

    $this->artisan('sentinel:admin', ['email' => 'x@example.org', '--role' => 'root'])->assertFailed();
});

// --- two-factor authentication -------------------------------------------------------------------------------------

it('computes the RFC 6238 test codes and refuses a code used twice', function () {
    $totp = new Totp;
    // The public test key of RFC 6238, appendix B: the ASCII seed "12345678901234567890" in base32.
    $secret = str_repeat('GEZDGNBVGY3TQOJQ', 2);

    expect($totp->code($secret, 59))->toBe('287082')
        ->and($totp->code($secret, 1111111109))->toBe('081804')
        ->and($totp->code($secret, 2000000000))->toBe('279037');

    $code = $totp->code($secret, time());
    expect($totp->verify($secret, $code, 'u1'))->toBeTrue()
        ->and($totp->verify($secret, $code, 'u1'))->toBeFalse()
        ->and($totp->verify($secret, '000000x', 'u2'))->toBeFalse();
});

it('accepts a code one step early or late, and no further', function () {
    $totp = new Totp;
    $secret = $totp->generateSecret();
    $now = 1_800_000_000;

    expect($totp->verify($secret, $totp->code($secret, $now - 30), 'a', $now))->toBeTrue()
        ->and($totp->verify($secret, $totp->code($secret, $now + 90), 'b', $now))->toBeFalse();
});

it('asks for the code after the password and only then signs in', function () {
    $user = withTwoFactor(User::factory()->admin()->create(['email' => 'a@example.org', 'password' => 'correct-horse-battery']));

    Livewire::test(Login::class)->set('email', 'a@example.org')->set('password', 'correct-horse-battery')->call('login')
        ->assertRedirect(route('two-factor.challenge'));
    expect(Auth::check())->toBeFalse();

    Livewire::test(TwoFactorChallenge::class)->set('code', '123456')->call('verify')->assertHasErrors('code');
    expect(Auth::check())->toBeFalse();

    Livewire::test(TwoFactorChallenge::class)->set('code', (new Totp)->code('JBSWY3DPEHPK3PXP', time()))->call('verify')->assertRedirect(route('dashboard'));
    expect(Auth::id())->toBe($user->id);
});

it('lets a recovery code in once', function () {
    $user = withTwoFactor(User::factory()->admin()->create(['email' => 'a@example.org', 'password' => 'correct-horse-battery']));
    Livewire::test(Login::class)->set('email', 'a@example.org')->set('password', 'correct-horse-battery')->call('login');

    Livewire::test(TwoFactorChallenge::class)->set('recovery', true)->set('code', 'AAAA-bbbb')->call('verify')->assertRedirect(route('dashboard'));

    expect($user->fresh()->two_factor_recovery_codes)->toBe([]);
});

it('locks the challenge after five wrong codes', function () {
    withTwoFactor(User::factory()->admin()->create(['email' => 'a@example.org', 'password' => 'correct-horse-battery']));
    Livewire::test(Login::class)->set('email', 'a@example.org')->set('password', 'correct-horse-battery')->call('login');

    foreach (range(1, 5) as $i) {
        Livewire::test(TwoFactorChallenge::class)->set('code', '000000')->call('verify');
    }

    Livewire::test(TwoFactorChallenge::class)->set('code', (new Totp)->code('JBSWY3DPEHPK3PXP', time()))->call('verify')->assertHasErrors('code');
    expect(Auth::check())->toBeFalse();
});

it('sends users without two-factor to its setup and keeps the Livewire endpoint closed meanwhile', function () {
    config(['sentinel.auth.require_two_factor' => true]);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    Livewire::test(ActionsIndex::class)->assertForbidden();
});

it('pairs an authenticator app and shows the recovery codes once', function () {
    config(['sentinel.auth.require_two_factor' => true]);
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $setup = Livewire::test(TwoFactorSetup::class)->assertSee('<svg', false);
    $secret = session('two_factor.pending_secret');

    $setup->set('code', '111111')->call('confirm')->assertHasErrors('code');
    $setup->set('code', (new Totp)->code($secret, time()))->call('confirm')->assertHasNoErrors();

    expect($user->fresh()->hasTwoFactor())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(8)
        ->and($setup->get('recoveryCodes'))->toHaveCount(8);
    $this->get(route('dashboard'))->assertOk();
});

it('lets an administrator reset the two-factor of a user who lost their phone', function () {
    $this->actingAs(User::factory()->admin()->create());
    $user = withTwoFactor(User::factory()->viewer()->create());

    Livewire::test(UsersIndex::class)->call('resetTwoFactor', $user->id);

    expect($user->fresh()->hasTwoFactor())->toBeFalse();
});

// --- two-person approval -------------------------------------------------------------------------------------------

it('runs an action on a two-person machine only after two different users approved it', function () {
    $transport = accessTransport();
    $pending = heldAction(Machine::factory()->create(['two_person_approval' => true]));
    [$alice, $bob] = User::factory()->approver()->count(2)->create();

    $this->actingAs($alice);
    expect(app(ActionExecutor::class)->approve($pending->fresh()))->toStartWith('WAITING: first approval')
        ->and(app(ActionExecutor::class)->approve($pending->fresh()))->toStartWith('WAITING: you already approved')
        ->and($transport->commands)->toBeEmpty();

    $this->actingAs($bob);
    app(ActionExecutor::class)->approve($pending->fresh());

    expect($pending->fresh()->status)->toBe('executed')->and($transport->commands)->not->toBeEmpty();
});

it('refuses Telegram and command line approvals on a two-person machine', function () {
    $transport = accessTransport();
    $pending = heldAction(Machine::factory()->create(['two_person_approval' => true]));

    expect(app(ActionExecutor::class)->approve($pending->fresh()))->toStartWith('ERROR')
        ->and(app(ActionExecutor::class)->approve($pending->fresh(), ['via' => 'telegram', 'telegram_user_id' => '1']))->toStartWith('ERROR')
        ->and($pending->fresh()->status)->toBe('pending')
        ->and($transport->commands)->toBeEmpty();
});

it('needs both approvals before scheduling for the window, too', function () {
    accessTransport();
    $pending = heldAction(Machine::factory()->create(['two_person_approval' => true, 'maintenance_days' => [7], 'maintenance_start' => '03:00', 'maintenance_minutes' => 60]));
    [$alice, $bob] = User::factory()->approver()->count(2)->create();

    $this->actingAs($alice);
    expect(app(ActionExecutor::class)->schedule($pending->fresh()))->toStartWith('WAITING')->and($pending->fresh()->status)->toBe('pending');

    $this->actingAs($bob);
    expect(app(ActionExecutor::class)->schedule($pending->fresh()))->toBeNull()->and($pending->fresh()->status)->toBe('scheduled');
});

it('shows how many approvals an action has on the actions page', function () {
    accessTransport();
    $pending = heldAction(Machine::factory()->create(['two_person_approval' => true]));
    $alice = User::factory()->approver()->create(['name' => 'Alice']);
    $this->actingAs($alice);

    Livewire::test(ActionsIndex::class)->call('approve', $pending->id)->assertSee('1 of 2 approvals')->assertSee('approved by Alice');
});
