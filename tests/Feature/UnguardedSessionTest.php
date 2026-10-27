<?php

use App\Ai\Agents\SysadminAgent;
use App\Auth\Totp;
use App\Livewire\Actions\Index as ActionsIndex;
use App\Livewire\MachineChat;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Models\User;
use App\Ssh\AccessControl;
use App\Ssh\ActionExecutor;
use App\Ssh\CommandResult;
use App\Ssh\Provisioning\BundleSigner;
use App\Ssh\Provisioning\ClientBundle;
use App\Ssh\Provisioning\SudoersBuilder;
use App\Ssh\SshTransport;
use App\Ssh\UnguardedSession;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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

/** A machine with an open unguarded session, as UnguardedSession::open() leaves it. */
function unguardedMachine(array $attributes = []): Machine
{
    return Machine::factory()->create(['host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43), 'unguarded_until' => now()->addMinutes(5), 'unguarded_session' => (string) Str::uuid(), ...$attributes]);
}

/** The command a signed run call carries (what the machine would run as root), or null if $sent is not one. */
function signedRun(string $sent): ?string
{
    if (! preg_match("#^sudo -n /usr/local/sbin/sentinel-unguarded run '([A-Za-z0-9+/=]+)' '[A-Za-z0-9+/=]+' 2>&1$#", $sent, $m)
        || ! preg_match('/^SENTINEL-RUN 1 .* command=(\S+)$/', base64_decode($m[1]), $c)) {
        return null;
    }

    return base64_decode($c[1]);
}

/** The host key fingerprint of the machine running the tests, as the wrapper computes it. */
function localHostKey(): ?string
{
    $key = '/etc/ssh/ssh_host_ed25519_key.pub';

    return is_readable($key) ? explode(' ', Process::run(['ssh-keygen', '-lf', $key])->output())[1] ?? null : null;
}

/** Runs the lease wrapper's non-root "check" on a base64 token and signature. */
function checkLease(string $wrapper, string $token, string $signature): ProcessResult
{
    $file = tempnam(sys_get_temp_dir(), 'ung');
    file_put_contents($file, $wrapper);
    $result = Process::run(['bash', $file, 'check', $token, $signature]);
    unlink($file);

    return $result;
}

/** @return array{0: string, 1: string} A lease line signed by the current signing key, both base64. */
function signedLease(string $line, string $namespace = UnguardedSession::NAMESPACE): array
{
    return [base64_encode($line), base64_encode(app(BundleSigner::class)->sign($line, $namespace))];
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

    expect($body)->toContain('max_minutes=45', app(BundleSigner::class)->publicKey())->not->toContain('__UNGUARDED_TTL__', '__SIGNING_KEY__')
        ->and(Process::run(['bash', '-n', $file])->successful())->toBeTrue();
    unlink($file);
});

it('opens the lease through the wrapper with a signed token and records the session', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);

    app(UnguardedSession::class)->open($machine);

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toMatch("#^sudo -n /usr/local/sbin/sentinel-unguarded on '[A-Za-z0-9+/=]+' '[A-Za-z0-9+/=]+' 2>&1$#")
        ->and($machine->fresh()->unguardedActive())->toBeTrue()
        ->and($machine->fresh()->unguarded_session)->not->toBeNull()
        ->and(Activity::where('event', 'unguarded_opened')->count())->toBe(1);

    app(UnguardedSession::class)->close($machine);

    expect($commands[1])->toBe('sudo -n /usr/local/sbin/sentinel-unguarded off 2>&1')
        ->and($machine->fresh()->unguardedActive())->toBeFalse();
});

it('does not consider a session open when the machine refuses the lease', function () {
    $commands = [];
    leaseTransport($commands, failing: 'sentinel-unguarded on');
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);

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

    $machine->forceFill(['unguarded_until' => now()->addMinutes(5), 'unguarded_session' => (string) Str::uuid()])->save();

    expect($executor->proposeCommand($machine, 'ls /root', null, 'look around'))->toStartWith('PROPOSED')
        ->and($executor->proposeCommand($machine, 'ls /root', null, 'look around'))->toStartWith('ALREADY_PENDING')
        ->and($executor->proposeCommand($machine, '   ', null, 'x'))->toStartWith('ERROR')
        ->and($commands)->toBeEmpty();

    $pending = PendingAction::sole();
    expect($pending->action)->toBe('run_command')
        ->and($pending->risk)->toBe('high')
        ->and($pending->command)->toBe("bash -c 'ls /root'");
});

it('runs an approved command through a signed run of the lease wrapper, never through a sudo shell', function () {
    $commands = [];
    leaseTransport($commands);
    $this->actingAs(User::factory()->admin()->create());
    $machine = unguardedMachine();
    app(ActionExecutor::class)->proposeCommand($machine, "echo 'it works'; id", null, 'test');

    $output = app(ActionExecutor::class)->approve(PendingAction::sole(), ['surface' => 'chat']);

    expect($commands)->toHaveCount(1)
        ->and(signedRun($commands[0]))->toBe("echo 'it works'; id")
        ->and($output)->toBe('ok')
        ->and(PendingAction::sole()->status)->toBe('executed')
        ->and(Activity::where('event', 'action_approved')->count())->toBe(1);
});

it('leaves a command pending and unrun when the session ended, or the approval is not from the dashboard', function () {
    $commands = [];
    leaseTransport($commands);
    $this->actingAs(User::factory()->admin()->create());
    $machine = unguardedMachine();
    $executor = app(ActionExecutor::class);
    $executor->proposeCommand($machine, 'reboot', null, 'test');

    expect($executor->approve(PendingAction::sole(), ['via' => 'telegram']))->toContain('dashboard chat only')
        ->and($executor->approve(PendingAction::sole()))->toContain('dashboard chat only');

    $machine->forceFill(['unguarded_until' => now()->subMinute()])->save();

    expect($executor->approve(PendingAction::sole()->fresh(), ['surface' => 'chat']))->toContain('not open')
        ->and(PendingAction::sole()->status)->toBe('pending')
        ->and($commands)->toBeEmpty();
});

it('never schedules a raw command for a maintenance window', function () {
    $machine = unguardedMachine(['maintenance_days' => [1], 'maintenance_start' => '03:00', 'maintenance_minutes' => 60]);
    app(ActionExecutor::class)->proposeCommand($machine, 'uptime', null, 'test');

    expect(fn () => app(ActionExecutor::class)->schedule(PendingAction::sole()))->toThrow(HttpException::class);
});

it('starts and ends the session from the chat for admins only', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);

    $this->actingAs(User::factory()->approver()->create());
    Livewire::test(MachineChat::class, ['machine' => $machine])->call('openUnguarded', 'password')->assertForbidden();
    expect($commands)->toBeEmpty();

    $this->actingAs(User::factory()->admin()->create());
    $chat = Livewire::test(MachineChat::class, ['machine' => $machine])
        ->assertSee('Start an unguarded session?')->assertSee('I understand')->assertSee('Your password')
        ->call('openUnguarded', 'password')
        ->assertReturned(true)
        ->assertSee('Read each one before approving');

    expect($machine->fresh()->unguardedActive())->toBeTrue();

    // Starting again from a stale page does not end the session.
    $chat->call('openUnguarded', 'password');
    expect($machine->fresh()->unguardedActive())->toBeTrue();

    $chat->call('closeUnguarded');
    expect($machine->fresh()->unguardedActive())->toBeFalse();
});

it('does not start a session without a fresh proof that the admin is at the keyboard', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $secret = app(Totp::class)->generateSecret();
    $admin = User::factory()->admin()->create(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);
    $this->actingAs($admin);
    $chat = Livewire::test(MachineChat::class, ['machine' => $machine])->assertSee('Authentication code');

    // With two-factor on, the password is not enough: only a current code is.
    $chat->call('openUnguarded', 'password')->assertReturned(false);
    $chat->call('openUnguarded', '000000')->assertReturned(false);

    expect($commands)->toBeEmpty()
        ->and($machine->fresh()->unguardedActive())->toBeFalse()
        ->and(Activity::where('event', 'unguarded_step_up_failed')->count())->toBe(2);

    $chat->call('openUnguarded', app(Totp::class)->code($secret, time()))->assertReturned(true);

    expect($machine->fresh()->unguardedActive())->toBeTrue();
});

it('stops trying proofs after five wrong ones in a minute', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $this->actingAs(User::factory()->admin()->create());
    $chat = Livewire::test(MachineChat::class, ['machine' => $machine]);

    foreach (range(1, 5) as $attempt) {
        $chat->call('openUnguarded', 'wrong');
    }
    $chat->call('openUnguarded', 'password')->assertReturned(false);

    expect($commands)->toBeEmpty()->and($machine->fresh()->unguardedActive())->toBeFalse();
});

it('approves a proposed command from the chat and tells the agent the outcome on the next message', function () {
    $commands = [];
    leaseTransport($commands);
    SysadminAgent::fake(['first', 'second']);
    $this->actingAs(User::factory()->admin()->create());
    $machine = unguardedMachine();
    app(ActionExecutor::class)->proposeCommand($machine, 'df -h', null, 'disk check');
    $id = PendingAction::sole()->id;

    $chat = Livewire::test(MachineChat::class, ['machine' => $machine])->assertSee('df -h')->assertSee('Approve and run as root');
    $chat->set('message', 'hello')->call('send');
    $chat->call('approveCommand', $id)->assertSee('executed');
    $chat->set('message', 'and now?')->call('send');

    expect(PendingAction::sole()->status)->toBe('executed')
        ->and($commands)->toHaveCount(1)
        ->and(signedRun($commands[0]))->toBe('df -h');

    SysadminAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '<command_output>') && str_contains($prompt->prompt, 'untrusted'));
});

it('ends the session when access to the machine is revoked', function () {
    $machine = unguardedMachine();

    app(AccessControl::class)->revoke($machine);

    expect($machine->fresh())->unguardedActive()->toBeFalse()->unguarded_until->toBeNull()->unguarded_session->toBeNull();
});

it('issues a lease token that the wrapper accepts for this machine only', function () {
    $host = localHostKey() ?? $this->markTestSkipped('no readable ed25519 host key');
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create(['host_key_fingerprint' => $host]);

    app(UnguardedSession::class)->open($machine);
    preg_match("#on '([^']+)' '([^']+)'#", $commands[0], $m);
    $result = checkLease(app(ClientBundle::class)->wrappers()['sentinel-unguarded'], $m[1], $m[2]);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('30-minute lease');
});

it('refuses lease tokens Sentinel did not issue, or not for now and here', function (Closure $lease, int $exit, string $error) {
    $host = localHostKey() ?? $this->markTestSkipped('no readable ed25519 host key');
    $wrapper = app(ClientBundle::class)->wrappers()['sentinel-unguarded'];
    $valid = fn (array $overrides = []) => vsprintf('SENTINEL-LEASE 2 host=%s session=%s not_after=%d minutes=%d', [...['host' => $host, 'session' => str_repeat('ab', 16), 'not_after' => time() + 60, 'minutes' => 30], ...$overrides]);

    [$token, $signature] = $lease($valid);
    $result = checkLease($wrapper, $token, $signature);

    expect($result->exitCode())->toBe($exit)
        ->and($result->errorOutput())->toContain($error);
})->with([
    'signed by another key' => [function (Closure $valid) {
        config(['sentinel.actions.bundle_signing_key' => sys_get_temp_dir().'/other-signing-'.uniqid().'.key']);
        app()->forgetInstance(BundleSigner::class);

        return signedLease($valid());
    }, 3, 'not signed by Sentinel'],
    'a bundle signature' => [fn (Closure $valid) => signedLease($valid(), BundleSigner::NAMESPACE), 3, 'not signed by Sentinel'],
    'changed after signing' => [fn (Closure $valid) => [base64_encode($valid(['minutes' => 31])), signedLease($valid())[1]], 3, 'not signed by Sentinel'],
    'expired' => [fn (Closure $valid) => signedLease($valid(['not_after' => time() - 5])), 4, 'expired'],
    'valid for hours' => [fn (Closure $valid) => signedLease($valid(['not_after' => time() + 3600])), 4, 'too long'],
    'longer than the machine allows' => [fn (Closure $valid) => signedLease($valid(['minutes' => 31])), 4, 'at most 30 minutes'],
    'for another machine' => [fn (Closure $valid) => signedLease($valid(['host' => 'SHA256:'.str_repeat('A', 43)])), 4, 'another machine'],
    'not base64' => [fn () => ["x'; id #", 'c2ln'], 2, 'invalid token encoding'],
]);

it('refuses to open a session on a machine whose host key is not pinned', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create(['host_key_fingerprint' => null]);

    expect(fn () => app(UnguardedSession::class)->open($machine))->toThrow(RuntimeException::class, 'Pin the host key')
        ->and($commands)->toBeEmpty()
        ->and($machine->fresh()->unguardedActive())->toBeFalse();
});

it('only lets an administrator approve a command, and leaves it pending for an approver', function () {
    $commands = [];
    leaseTransport($commands);
    $this->actingAs(User::factory()->approver()->create());
    $machine = unguardedMachine();
    app(ActionExecutor::class)->proposeCommand($machine, 'id', null, 'test');

    expect(app(ActionExecutor::class)->approve(PendingAction::sole(), ['surface' => 'chat']))->toContain('only an administrator')
        ->and(PendingAction::sole()->status)->toBe('pending')
        ->and($commands)->toBeEmpty()
        ->and(Activity::where('event', 'action_approval_denied')->count())->toBe(1);

    Livewire::test(MachineChat::class, ['machine' => $machine])
        ->assertSee('id')->assertDontSee('Approve and run as root')
        ->call('approveCommand', PendingAction::sole()->id)->assertForbidden();
});

it('never runs a command proposed in an earlier session', function () {
    $commands = [];
    leaseTransport($commands);
    $this->actingAs(User::factory()->admin()->create());
    $machine = unguardedMachine();
    app(ActionExecutor::class)->proposeCommand($machine, 'id', null, 'test');
    $machine->forceFill(['unguarded_session' => (string) Str::uuid()])->save();

    expect(app(ActionExecutor::class)->approve(PendingAction::sole(), ['surface' => 'chat']))->toContain('earlier unguarded session')
        ->and(PendingAction::sole()->status)->toBe('pending')
        ->and($commands)->toBeEmpty();
});

it('rejects the commands left waiting when a session ends, and those of an expired one when the next starts', function () {
    $commands = [];
    leaseTransport($commands);
    $machine = unguardedMachine(['host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    app(ActionExecutor::class)->proposeCommand($machine, 'id', null, 'test');

    app(UnguardedSession::class)->close($machine);

    expect(PendingAction::sole()->status)->toBe('rejected');

    $machine = unguardedMachine(['host_key_fingerprint' => 'SHA256:'.str_repeat('B', 43)]);
    app(ActionExecutor::class)->proposeCommand($machine, 'uptime', null, 'test');
    $machine->forceFill(['unguarded_until' => now()->subMinute()])->save();

    app(UnguardedSession::class)->open($machine);

    expect(PendingAction::where('machine_id', $machine->id)->sole()->status)->toBe('rejected');
});

it('keeps raw commands read-only on the Actions page: they are approved in the chat only', function () {
    $commands = [];
    leaseTransport($commands);
    $this->actingAs(User::factory()->admin()->create());
    $machine = unguardedMachine();
    app(ActionExecutor::class)->proposeCommand($machine, 'id', null, 'test');

    Livewire::test(ActionsIndex::class)
        ->assertSee('approves it in the chat of this machine')->assertDontSee('Approve and run')
        ->call('approve', PendingAction::sole()->id);

    expect(PendingAction::sole()->status)->toBe('pending')
        ->and($commands)->toBeEmpty();
});

it('closes the lease when the machine could not be reached while opening it', function () {
    $commands = [];
    app()->instance(SshTransport::class, new class($commands) implements SshTransport
    {
        public function __construct(private array &$commands) {}

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return str_contains($command, ' on ') ? throw new RuntimeException('timed out') : new CommandResult('ok', 0);
        }
    });
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);

    expect(fn () => app(UnguardedSession::class)->open($machine))->toThrow(RuntimeException::class, 'Could not reach')
        ->and($commands[1] ?? null)->toBe('sudo -n /usr/local/sbin/sentinel-unguarded off 2>&1')
        ->and($machine->fresh()->unguardedActive())->toBeFalse();
});

it('runs exactly the signed command as root on the machine, once, in the open lease only, and never grants sudo', function () {
    $host = localHostKey() ?? $this->markTestSkipped('no readable ed25519 host key');
    if (Process::run(['unshare', '-rm', 'true'])->failed()) {
        $this->markTestSkipped('user namespaces are not available to stand in for root');
    }

    $commands = [];
    leaseTransport($commands);
    $machine = Machine::factory()->create(['host_key_fingerprint' => $host]);
    app(UnguardedSession::class)->open($machine);
    $machine->refresh();
    $args = fn (string $sent) => preg_match("#(?:on|run) ('[^']+' '[^']+')#", $sent, $m) ? $m[1] : '';
    $other = (clone $machine)->forceFill(['unguarded_session' => (string) Str::uuid()]);

    $dir = sys_get_temp_dir().'/unguarded-'.uniqid();
    mkdir("{$dir}/lib", 0700, true);
    mkdir("{$dir}/sudoers");
    file_put_contents("{$dir}/wrapper", app(ClientBundle::class)->wrappers()['sentinel-unguarded']);
    $run = $args(app(UnguardedSession::class)->runCommand($machine, 'id -u'));
    file_put_contents("{$dir}/steps.sh", implode("\n", [
        "mount --bind {$dir}/lib /var/lib && mount --bind {$dir}/sudoers /etc/sudoers.d || exit 99",
        'step() { echo "== $1"; shift; "$@"; echo "exit=$?"; }',
        "step on bash {$dir}/wrapper on ".$args($commands[0]),
        "step run bash {$dir}/wrapper run {$run}",
        "step replay bash {$dir}/wrapper run {$run}",
        "step other-session bash {$dir}/wrapper run ".$args(app(UnguardedSession::class)->runCommand($other, 'id -u')),
        'sed -i "s/ [^ ]*$/ 00000000-0000-0000-0000-000000000000/" /var/lib/sentinel-unguarded/lease',
        "step after-reboot bash {$dir}/wrapper run ".$args(app(UnguardedSession::class)->runCommand($machine, 'id -u')),
        '[ -e /var/lib/sentinel-unguarded/lease ] || echo "lease removed"',
        'echo "sudoers: $(ls -A /etc/sudoers.d)."',
    ]));

    $output = Process::run(['unshare', '-rm', 'bash', "{$dir}/steps.sh"])->output().Process::run(['rm', '-rf', $dir])->output();

    expect($output)->toContain("== on\nunguarded session open for 30 minutes\nexit=0")
        ->toContain("== run\n0\nexit=0")
        ->toContain("== replay\nexit=4")
        ->toContain("== other-session\nexit=4")
        ->toContain("== after-reboot\nexit=4", 'lease removed', 'sudoers: .');
});
