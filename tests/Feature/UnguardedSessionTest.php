<?php

use App\Ai\Agents\SysadminAgent;
use App\Livewire\MachineChat;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Models\User;
use App\Ssh\AccessControl;
use App\Ssh\ActionExecutor;
use App\Ssh\CommandResult;
use App\Ssh\Provisioning\ClientBundle;
use App\Ssh\Provisioning\SudoersBuilder;
use App\Ssh\SshTransport;
use App\Ssh\UnguardedSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => config(['sentinel.actions.use_sudo' => true]));

/** Records what runs on the machine; every command succeeds unless a failing prefix is given. */
function leaseTransport(array &$commands, ?string $failing = null): void
{
    app()->instance(SshTransport::class, new class($commands, $failing) implements SshTransport
    {
        public function __construct(private array &$commands, private ?string $failing) {}

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return $this->failing && str_contains($command, $this->failing) ? new CommandResult('boom', 4) : new CommandResult('ok', 0);
        }
    });
}

function agentToolNames(Machine $machine, bool $unguarded): array
{
    return collect(iterator_to_array((new SysadminAgent($machine, unguarded: $unguarded))->tools(), false))
        ->map(fn (Tool $t) => $t->name())->all();
}

it('keeps the root lease and raw command out of the agent tools unless a session is open', function () {
    $machine = Machine::factory()->create();

    expect(agentToolNames($machine, true))->not->toContain('propose_command', 'run_command', 'unguarded_open', 'propose_run_command', 'propose_unguarded_open');

    $machine->forceFill(['unguarded_until' => now()->addMinutes(5)])->save();

    expect(agentToolNames($machine, false))->not->toContain('propose_command')
        ->and(agentToolNames($machine, true))->toContain('propose_command')->not->toContain('run_command', 'unguarded_open', 'unguarded_close');
});

it('puts the lease wrapper in the sudo policy with a rule the updater accepts', function () {
    $rules = app(SudoersBuilder::class)->rules();

    expect($rules)->toContain('/usr/local/sbin/sentinel-unguarded *')
        ->and(app(SudoersBuilder::class)->render('sentinel'))->not->toContain('NOPASSWD: ALL');
});

it('ships a lease wrapper that is valid bash with the lease length filled in', function () {
    config(['sentinel.unguarded.lease_minutes' => 45]);
    $body = app(ClientBundle::class)->wrappers()['sentinel-unguarded'];
    $file = tempnam(sys_get_temp_dir(), 'ung');
    file_put_contents($file, $body);

    expect($body)->toContain('ttl=45')->not->toContain('__UNGUARDED_TTL__')
        ->and(Process::run(['bash', '-n', $file])->successful())->toBeTrue();
    unlink($file);
});

it('opens the lease through the wrapper and records the session', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create();

    app(UnguardedSession::class)->open($machine);

    expect($commands)->toBe(['sudo -n /usr/local/sbin/sentinel-unguarded on 2>&1'])
        ->and($machine->fresh()->unguardedActive())->toBeTrue()
        ->and(Activity::where('event', 'unguarded_opened')->count())->toBe(1);

    app(UnguardedSession::class)->close($machine);

    expect($commands[1])->toBe('sudo -n /usr/local/sbin/sentinel-unguarded off 2>&1')
        ->and($machine->fresh()->unguardedActive())->toBeFalse();
});

it('does not consider a session open when the machine refuses the lease', function () {
    $commands = [];
    leaseTransport($commands, failing: 'sentinel-unguarded on');
    $machine = Machine::factory()->create();

    expect(fn () => app(UnguardedSession::class)->open($machine))->toThrow(RuntimeException::class, 'refused the lease')
        ->and($machine->fresh()->unguardedActive())->toBeFalse();
});

it('refuses a proposed command when no session is open, and files it without running it when one is', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create();
    $executor = app(ActionExecutor::class);

    expect($executor->proposeCommand($machine, 'ls /root', null, 'look around'))->toStartWith('REFUSED')
        ->and(PendingAction::count())->toBe(0);

    $machine->forceFill(['unguarded_until' => now()->addMinutes(5)])->save();

    expect($executor->proposeCommand($machine, 'ls /root', null, 'look around'))->toStartWith('PROPOSED')
        ->and($executor->proposeCommand($machine, 'ls /root', null, 'look around'))->toStartWith('ALREADY_PENDING')
        ->and($executor->proposeCommand($machine, '   ', null, 'x'))->toStartWith('ERROR')
        ->and($commands)->toBeEmpty();

    $pending = PendingAction::sole();
    expect($pending->action)->toBe('run_command')
        ->and($pending->risk)->toBe('high')
        ->and($pending->command)->toBe("sudo -n bash -c 'ls /root' 2>&1");
});

it('runs an approved command as root, quoted as one argument', function () {
    $commands = [];
    leaseTransport($commands);
    $this->actingAs(User::factory()->approver()->create());
    $machine = Machine::factory()->create(['unguarded_until' => now()->addMinutes(5)]);
    app(ActionExecutor::class)->proposeCommand($machine, "echo 'it works'; id", null, 'test');

    $output = app(ActionExecutor::class)->approve(PendingAction::sole());

    expect($commands)->toBe(["sudo -n bash -c 'echo '\\''it works'\\''; id' 2>&1"])
        ->and($output)->toBe('ok')
        ->and(PendingAction::sole()->status)->toBe('executed')
        ->and(Activity::where('event', 'action_approved')->count())->toBe(1);
});

it('leaves a command pending and unrun when the session ended, or the approval is not from the dashboard', function () {
    $commands = [];
    leaseTransport($commands);
    $this->actingAs(User::factory()->approver()->create());
    $machine = Machine::factory()->create(['unguarded_until' => now()->addMinutes(5)]);
    $executor = app(ActionExecutor::class);
    $executor->proposeCommand($machine, 'reboot', null, 'test');

    expect($executor->approve(PendingAction::sole(), ['via' => 'telegram']))->toContain('dashboard chat only');

    $machine->forceFill(['unguarded_until' => now()->subMinute()])->save();

    expect($executor->approve(PendingAction::sole()->fresh()))->toContain('not open')
        ->and(PendingAction::sole()->status)->toBe('pending')
        ->and($commands)->toBeEmpty();
});

it('never schedules a raw command for a maintenance window', function () {
    $machine = Machine::factory()->create(['unguarded_until' => now()->addMinutes(5), 'maintenance_days' => [1], 'maintenance_start' => '03:00', 'maintenance_minutes' => 60]);
    app(ActionExecutor::class)->proposeCommand($machine, 'uptime', null, 'test');

    expect(fn () => app(ActionExecutor::class)->schedule(PendingAction::sole()))->toThrow(HttpException::class);
});

it('starts and ends the session from the chat for admins only', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create();

    $this->actingAs(User::factory()->approver()->create());
    Livewire::test(MachineChat::class, ['machine' => $machine])->call('toggleUnguarded')->assertForbidden();
    expect($commands)->toBeEmpty();

    $this->actingAs(User::factory()->admin()->create());
    $chat = Livewire::test(MachineChat::class, ['machine' => $machine])
        ->assertSee('Start an unguarded session?')->assertSee('I understand')
        ->call('toggleUnguarded')
        ->assertSee('Read each one before approving');

    expect($machine->fresh()->unguardedActive())->toBeTrue();

    $chat->call('toggleUnguarded');
    expect($machine->fresh()->unguardedActive())->toBeFalse();
});

it('approves a proposed command from the chat and tells the agent the outcome on the next message', function () {
    $commands = [];
    leaseTransport($commands);
    SysadminAgent::fake(['first', 'second']);
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create(['unguarded_until' => now()->addMinutes(5)]);
    app(ActionExecutor::class)->proposeCommand($machine, 'df -h', null, 'disk check');
    $id = PendingAction::sole()->id;

    $chat = Livewire::test(MachineChat::class, ['machine' => $machine])->assertSee('df -h')->assertSee('Approve and run as root');
    $chat->set('message', 'hello')->call('send');
    $chat->call('approveCommand', $id)->assertSee('executed');
    $chat->set('message', 'and now?')->call('send');

    expect(PendingAction::sole()->status)->toBe('executed')
        ->and($commands)->toBe(["sudo -n bash -c 'df -h' 2>&1"]);

    SysadminAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '<command_output>') && str_contains($prompt->prompt, 'untrusted'));
});

it('ends the session when access to the machine is revoked', function () {
    $machine = Machine::factory()->create(['unguarded_until' => now()->addMinutes(5)]);

    app(AccessControl::class)->revoke($machine);

    expect($machine->fresh()->unguardedActive())->toBeFalse()->and($machine->fresh()->unguarded_until)->toBeNull();
});
