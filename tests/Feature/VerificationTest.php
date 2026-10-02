<?php

use App\Models\Machine;
use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Notifications\MachineAlertNotification;
use App\Ssh\ActionCatalog;
use App\Ssh\ActionExecutor;
use App\Ssh\AuditTrail;
use App\Ssh\CommandResult;
use App\Ssh\Gate\RiskGate;
use App\Ssh\SshTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Answers each command by the first matching substring in $answers, "ok" otherwise. */
function scripted(array $answers): object
{
    return new class($answers) implements SshTransport
    {
        public array $commands = [];

        public function __construct(private array $answers) {}

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            foreach ($this->answers as $needle => [$output, $exit]) {
                if (str_contains($command, $needle)) {
                    return new CommandResult($output, $exit);
                }
            }

            return new CommandResult('ok', 0);
        }
    };
}

function verifyingExecutor(object $transport): ActionExecutor
{
    return new ActionExecutor(ActionCatalog::default(), $transport, new RiskGate, new AuditTrail);
}

function approved(ActionExecutor $executor, Machine $machine, string $action, array $arguments): array
{
    $executor->propose($machine, $action, $arguments, null, 'test');
    $pending = PendingAction::latest('id')->first();

    return [$executor->approve($pending), $pending->fresh()];
}

beforeEach(function () {
    Notification::fake();
    NotificationChannel::create(['name' => 'ops', 'type' => 'mail', 'settings' => ['email' => 'ops@example.com'], 'min_severity' => 'low']);
    config(['sentinel.actions.restartable_services' => ['nginx'], 'sentinel.actions.package_allowlist' => ['nginx']]);
});

it('confirms a restart when the service is active afterwards', function () {
    $transport = scripted(['is-active' => ["active\n", 0]]);
    [$output, $pending] = approved(verifyingExecutor($transport), Machine::factory()->create(), 'restart_service', ['service' => 'nginx']);

    expect($output)->toEndWith('VERIFIED: nginx is active.')
        ->and($pending->status)->toBe('executed')
        ->and(Activity::where('event', 'action_verified')->count())->toBe(1);
    Notification::assertNotSentTo(NotificationChannel::first(), MachineAlertNotification::class);
});

it('marks an approved action failed and alerts when its check fails', function () {
    $transport = scripted(['is-active' => ["failed\n", 3]]);
    [$output, $pending] = approved(verifyingExecutor($transport), Machine::factory()->create(), 'restart_service', ['service' => 'nginx']);

    expect($output)->toContain('VERIFICATION FAILED: expected nginx is active, the check said: failed')
        ->and($pending->status)->toBe('failed')
        ->and(Activity::where('event', 'action_verification_failed')->count())->toBe(1);
    Notification::assertSentTimes(MachineAlertNotification::class, 1);
});

it('rolls a web configuration back at once when it fails its test after a reload', function () {
    $transport = scripted(["web-config test 'nginx'" => ['emerg: unexpected }', 1]]);
    [$output] = approved(verifyingExecutor($transport), Machine::factory()->create(), 'reload_web_config', ['service' => 'nginx']);

    expect($output)->toContain('VERIFICATION FAILED')->toContain('ROLLED BACK with rollback_web_config')
        ->and(collect($transport->commands)->contains(fn ($c) => str_contains($c, "rollback 'nginx'")))->toBeTrue()
        ->and(Activity::where('event', 'action_rolled_back')->count())->toBe(1);
});

it('checks that the package is installed after an upgrade', function () {
    $transport = scripted(['dpkg-query' => ['install ok installed', 0]]);
    [$output] = approved(verifyingExecutor($transport), Machine::factory()->create(), 'update_package', ['package' => 'nginx']);

    expect($output)->toEndWith('VERIFIED: nginx is installed.')
        ->and(end($transport->commands))->toBe("dpkg-query -W -f='\${Status}' -- 'nginx' 2>&1");
});

it('checks the settings sshd really applies after hardening', function (string $effective, bool $ok) {
    $transport = scripted(['sshd -T' => [$effective, 0]]);
    [$output] = approved(verifyingExecutor($transport), Machine::factory()->create(), 'harden_ssh', ['permit_root_login' => 'prohibit-password', 'password_authentication' => 'no']);

    expect(str_contains($output, 'VERIFIED'))->toBe($ok);
})->with([
    'applied' => ["permitrootlogin without-password\npasswordauthentication no", true],
    'overridden elsewhere' => ["permitrootlogin without-password\npasswordauthentication yes", false],
]);

it('does not check actions that declare no verification', function () {
    $transport = scripted([]);
    [$output] = approved(verifyingExecutor($transport), Machine::factory()->create(), 'clean_apt_cache', []);

    expect($output)->toBe('ok')->and($transport->commands)->toHaveCount(1);
});

it('treats an unreachable machine during the check as a failed check', function () {
    $transport = new class implements SshTransport
    {
        public int $calls = 0;

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            if (++$this->calls > 1) {
                throw new RuntimeException('connection lost');
            }

            return new CommandResult('', 0);
        }
    };

    [$output, $pending] = approved(verifyingExecutor($transport), Machine::factory()->create(), 'restart_service', ['service' => 'nginx']);

    expect($output)->toContain('VERIFICATION FAILED')->and($pending->status)->toBe('failed');
});
