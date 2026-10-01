<?php

use App\Ai\Agents\SysadminAgent;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\ActionCatalog;
use App\Ssh\ActionExecutor;
use App\Ssh\AuditTrail;
use App\Ssh\CommandResult;
use App\Ssh\Gate\RiskGate;
use App\Ssh\SafeExecutor;
use App\Ssh\SshTransport;
use App\Ssh\ToolCatalog;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function recordingTransport(): object
{
    return new class implements SshTransport
    {
        public array $commands = [];

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult('done', 0);
        }
    };
}

function actions(object $transport): ActionExecutor
{
    return new ActionExecutor(ActionCatalog::default(), $transport, new RiskGate, new AuditTrail);
}

function jev(float $destructive, float $reversible, float $matches, string $choice, float $p): array
{
    $others = (1 - $p) / 2;
    $probabilities = collect(['execute', 'ask_human', 'refuse'])->mapWithKeys(fn ($o) => [$o => $o === $choice ? $p : $others])->all();

    return [[
        'destructive' => new BooleanAnswer($destructive),
        'reversible' => new BooleanAnswer($reversible),
        'matches_objective' => new BooleanAnswer($matches),
        'verdict' => new ChoiceAnswer($choice, $probabilities, $p),
    ]];
}

it('executes a low-risk action when the model is confident and autonomy is on', function () {
    Classification::fake(jev(0.01, 0.99, 0.97, 'execute', 0.99));
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);

    $out = actions($transport)->request($machine, 'clean_apt_cache', [], null, 'free disk space');

    expect($out)->toBe('done')
        ->and($transport->commands)->toBe(['apt-get clean 2>&1'])
        ->and(Activity::where('event', 'action_executed')->count())->toBe(1);
});

it('asks a human when the model is not confident enough', function () {
    Classification::fake(jev(0.02, 0.9, 0.9, 'execute', 0.7));
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);

    $out = actions($transport)->request($machine, 'clean_apt_cache', [], null, 'free disk space');

    expect($out)->toStartWith('PENDING_HUMAN_APPROVAL')
        ->and($transport->commands)->toBeEmpty()
        ->and(PendingAction::count())->toBe(1);
});

it('refuses when the model says refuse', function () {
    Classification::fake(jev(0.9, 0.1, 0.5, 'refuse', 0.9));
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);

    expect(actions($transport)->request($machine, 'vacuum_journal', [], null, 'x'))->toStartWith('REFUSED')
        ->and($transport->commands)->toBeEmpty();
});

it('fails closed when the model is unavailable', function () {
    Classification::fake(fn () => throw new RuntimeException('boom'));
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);

    expect(actions($transport)->request($machine, 'vacuum_journal', [], null, 'x'))->toStartWith('REFUSED')
        ->and($transport->commands)->toBeEmpty();
});

it('never consults the model nor runs when autonomy is disabled', function () {
    Classification::fake()->preventStrayClassifications();
    $transport = recordingTransport();
    $machine = Machine::factory()->create();

    expect(actions($transport)->request($machine, 'vacuum_journal', [], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL')
        ->and($transport->commands)->toBeEmpty();
    Classification::assertNothingClassified();
});

it('always requires approval for medium-risk actions, and enforces the allowlist', function () {
    config(['sentinel.actions.restartable_services' => ['nginx']]);
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);

    expect(actions($transport)->request($machine, 'restart_service', ['service' => 'nginx'], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL')
        ->and(actions($transport)->request($machine, 'restart_service', ['service' => 'mysql'], null, 'x'))->toStartWith('ERROR')
        ->and(actions($transport)->request($machine, 'restart_service', ['service' => 'nginx; reboot'], null, 'x'))->toStartWith('ERROR')
        ->and($transport->commands)->toBeEmpty();
});

it('runs a pending action only after human approval', function () {
    config(['sentinel.actions.restartable_services' => ['nginx']]);
    $transport = recordingTransport();
    $machine = Machine::factory()->create();
    $executor = actions($transport);

    $executor->request($machine, 'restart_service', ['service' => 'nginx'], null, 'x');
    $pending = PendingAction::first();
    $executor->approve($pending);

    expect($transport->commands)->toBe(["systemctl restart -- 'nginx' 2>&1"])
        ->and($pending->fresh()->status)->toBe('executed');
});

it('caps autonomous actions per scan', function () {
    Classification::fake(fn () => jev(0.01, 0.99, 0.97, 'execute', 0.99)[0]);
    config(['sentinel.gate.max_autonomous_actions_per_run' => 1]);
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);
    $run = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'x', 'objective' => 'x']);
    $executor = actions($transport);

    $first = $executor->request($machine, 'clean_apt_cache', [], $run, 'x');
    $second = $executor->request($machine, 'vacuum_journal', [], $run, 'x');

    expect($first)->toBe('done')->and($second)->toStartWith('PENDING_HUMAN_APPROVAL')->and($transport->commands)->toHaveCount(1);
});

it('upgrades a single installed package only after approval, without installing or removing others', function () {
    config(['sentinel.actions.package_allowlist' => ['curl']]);
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);
    $executor = actions($transport);

    expect($executor->request($machine, 'update_package', ['package' => 'curl'], null, 'patch curl'))->toStartWith('PENDING_HUMAN_APPROVAL')
        ->and($transport->commands)->toBeEmpty();

    $executor->approve(PendingAction::first());

    expect($transport->commands[0])->toBe("/usr/local/sbin/sentinel-upgrade-package 'curl' 2>&1");
});

it('rejects malicious or protected package names', function (string $package) {
    config(['sentinel.actions.package_allowlist' => ['*']]);
    $transport = recordingTransport();

    expect(actions($transport)->request(Machine::factory()->create(), 'update_package', ['package' => $package], null, 'x'))->toStartWith('ERROR')
        ->and($transport->commands)->toBeEmpty()
        ->and(PendingAction::count())->toBe(0);
})->with(['curl; reboot', '$(id)', '--purge', 'Curl', 'a b', '', 'openssh-server', 'libc6', 'linux-image-6.8.0', 'systemd-sysv', 'mysql-server']);

it('prefixes actions with non-interactive sudo when enabled', function () {
    config(['sentinel.actions.package_allowlist' => ['curl'], 'sentinel.actions.use_sudo' => true, 'sentinel.actions.restartable_services' => ['nginx']]);

    expect(ActionCatalog::default()->get('clean_apt_cache')->command([]))->toStartWith('sudo -n apt-get clean')
        ->and(ActionCatalog::default()->get('restart_service')->command(['service' => 'nginx']))->toStartWith('sudo -n systemctl restart')
        ->and(ActionCatalog::default()->get('update_package')->command(['package' => 'curl']))->toStartWith('sudo -n /usr/local/sbin/sentinel-upgrade-package');
});

it('gates the new service, certificate and fail2ban actions', function () {
    config(['sentinel.actions.restartable_services' => ['nginx'], 'sentinel.actions.reloadable_services' => ['php8.3-fpm']]);
    $transport = recordingTransport();
    $machine = Machine::factory()->create();
    $executor = actions($transport);

    $held = [
        ['reload_service', ['service' => 'php8.3-fpm']],
        ['renew_certificates', []],
        ['fail2ban_unban', ['jail' => 'sshd', 'ip' => '203.0.113.7']],
        ['fail2ban_write_filter', ['name' => 'nginx-login', 'failregex' => '^<HOST> .* "POST /login']],
        ['fail2ban_create_jail', ['name' => 'nginx-login', 'filter' => 'nginx-login', 'log' => 'nginx-access', 'maxretry' => 5, 'findtime' => 600, 'bantime' => 3600]],
        ['fail2ban_remove_custom', ['name' => 'nginx-login']],
    ];

    foreach ($held as [$action, $args]) {
        expect($executor->request($machine, $action, $args, null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL');
    }

    expect($transport->commands)->toBeEmpty()->and(PendingAction::count())->toBe(6);
});

it('rejects invalid arguments for the new actions', function (string $action, array $args) {
    config(['sentinel.actions.restartable_services' => ['nginx'], 'sentinel.actions.reloadable_services' => ['nginx']]);
    $transport = recordingTransport();

    expect(actions($transport)->request(Machine::factory()->create(), $action, $args, null, 'x'))->toStartWith('ERROR')
        ->and($transport->commands)->toBeEmpty();
})->with([
    'reload: not allowlisted' => ['reload_service', ['service' => 'mysql']],
    'reset-failed: not allowlisted' => ['reset_failed_unit', ['service' => 'cron']],
    'unban: bad ip' => ['fail2ban_unban', ['jail' => 'sshd', 'ip' => '1.2.3.4; id']],
    'unban: bad jail' => ['fail2ban_unban', ['jail' => 'sshd;id', 'ip' => '1.2.3.4']],
    'filter: leading dash regex' => ['fail2ban_write_filter', ['name' => 'x1', 'failregex' => '--help <HOST>']],
    'filter: interpolation' => ['fail2ban_write_filter', ['name' => 'x1', 'failregex' => '^%(__prefix_line)s <HOST>']],
    'filter: no HOST' => ['fail2ban_write_filter', ['name' => 'x1', 'failregex' => '^nothing here$']],
    'filter: 6 lines' => ['fail2ban_write_filter', ['name' => 'x1', 'failregex' => implode("\n", array_fill(0, 6, '^<HOST> x'))]],
    'filter: leading space on 2nd line' => ['fail2ban_write_filter', ['name' => 'x1', 'failregex' => "^<HOST> ok\n ^<HOST> x"]],
    'filter: control char' => ['fail2ban_write_filter', ['name' => 'x1', 'failregex' => "^<HOST> \x01"]],
    'filter: traversal name' => ['fail2ban_write_filter', ['name' => '../x', 'failregex' => '^<HOST> x']],
    'jail: unknown log' => ['fail2ban_create_jail', ['name' => 'a1', 'filter' => 'a1', 'log' => '/etc/shadow', 'maxretry' => 5, 'findtime' => 600, 'bantime' => 3600]],
    'jail: ban too long' => ['fail2ban_create_jail', ['name' => 'a1', 'filter' => 'a1', 'log' => 'sshd', 'maxretry' => 5, 'findtime' => 600, 'bantime' => 99999999]],
    'jail: maxretry too low' => ['fail2ban_create_jail', ['name' => 'a1', 'filter' => 'a1', 'log' => 'sshd', 'maxretry' => 1, 'findtime' => 600, 'bantime' => 3600]],
    'jail: extra action key ignored but bad type' => ['fail2ban_create_jail', ['name' => 'a1', 'filter' => 'a1', 'log' => 'sshd', 'maxretry' => '5; id', 'findtime' => 600, 'bantime' => 3600]],
    'remove: traversal' => ['fail2ban_remove_custom', ['name' => '../../etc']],
]);

it('tests a regex with fail2ban-regex on allowlisted logs only, without sudo', function () {
    $transport = recordingTransport();
    $machine = Machine::factory()->create();
    $executor = new SafeExecutor(ToolCatalog::default(), $transport);

    $executor->execute($machine, 'fail2ban_test_regex', ['log' => 'sshd', 'failregex' => '^Failed password for .* from <HOST>']);
    $rejected = $executor->execute($machine, 'fail2ban_test_regex', ['log' => '/etc/shadow', 'failregex' => '^<HOST>']);

    expect($transport->commands)->toBe(["timeout 15 fail2ban-regex -- '/var/log/auth.log' '^Failed password for .* from <HOST>' 2>&1 | tail -n 40"])
        ->and($rejected)->toStartWith('ERROR');
});

it('lets the agent see the new tools and actions', function () {
    $names = collect(iterator_to_array((new SysadminAgent(Machine::factory()->create()))->tools(), false))->map->name()->all();

    expect($names)->toContain('fail2ban_test_regex', 'fail2ban_write_filter', 'fail2ban_create_jail', 'reload_service', 'reset_failed_unit', 'renew_certificates', 'fail2ban_unban');
});

it('fails closed: packages outside the allowlist cannot be upgraded, even if not protected', function () {
    config(['sentinel.actions.package_allowlist' => ['nginx']]);
    $transport = recordingTransport();
    $machine = Machine::factory()->create();

    expect(actions($transport)->request($machine, 'update_package', ['package' => 'curl'], null, 'x'))->toStartWith('ERROR')
        ->and(actions($transport)->request($machine, 'update_package', ['package' => 'nginx'], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL');

    config(['sentinel.actions.package_allowlist' => []]);
    expect(actions($transport)->request($machine, 'update_package', ['package' => 'nginx'], null, 'x'))->toStartWith('ERROR');
});

it('never upgrades a denylisted package even when the allowlist matches it', function () {
    config(['sentinel.actions.package_allowlist' => ['*']]);

    expect(actions(recordingTransport())->request(Machine::factory()->create(), 'update_package', ['package' => 'openssl'], null, 'x'))->toStartWith('ERROR');
});

it('hides low-level failure details from the model but keeps them in the audit trail', function () {
    $transport = new class implements SshTransport
    {
        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            throw new RuntimeException('connect to 10.9.8.7:22 refused, key /home/x/.ssh/id');
        }
    };

    $out = (new SafeExecutor(ToolCatalog::default(), $transport))->execute(Machine::factory()->create(), 'disk_usage');

    expect($out)->not->toContain('10.9.8.7')
        ->and(Activity::where('event', 'failed')->first()->properties['output_excerpt'])->toContain('10.9.8.7');
});

it('builds the SSH hardening and security package commands from validated values only', function () {
    $catalog = ActionCatalog::default();

    expect($catalog->get('harden_ssh')->command(['permit_root_login' => 'prohibit-password', 'password_authentication' => 'no']))
        ->toBe("/usr/local/sbin/sentinel-sshd-harden 'prohibit-password' 'no' 2>&1")
        ->and($catalog->get('install_security_package')->command(['package' => 'fail2ban']))
        ->toBe("/usr/local/sbin/sentinel-install-package 'fail2ban' 2>&1")
        ->and($catalog->get('harden_ssh')->risk()->value)->toBe('medium')
        ->and($catalog->get('install_security_package')->risk()->value)->toBe('medium');

    expect(fn () => $catalog->get('harden_ssh')->command(['permit_root_login' => 'yes', 'password_authentication' => 'no']))->toThrow(InvalidToolArguments::class)
        ->and(fn () => $catalog->get('harden_ssh')->command(['permit_root_login' => 'keep', 'password_authentication' => 'keep']))->toThrow(InvalidToolArguments::class)
        ->and(fn () => $catalog->get('install_security_package')->command(['package' => 'nmap']))->toThrow(InvalidToolArguments::class);
});

it('lets an administrator enable autonomy for a single scan without touching the machine', function () {
    Classification::fake(jev(0.01, 0.99, 0.97, 'execute', 0.99));
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => false]);
    $run = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'test', 'objective' => 'x', 'status' => 'running', 'allow_actions' => true]);

    $out = actions($transport)->request($machine, 'clean_apt_cache', [], $run, 'free disk space');

    expect($out)->toBe('done')->and((bool) $machine->fresh()->autonomy_enabled)->toBeFalse();
});

it('still holds actions for a human when neither the machine nor the scan allows autonomy', function () {
    Classification::fake(jev(0.01, 0.99, 0.97, 'execute', 0.99));
    $machine = Machine::factory()->create(['autonomy_enabled' => false]);
    $run = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'test', 'objective' => 'x', 'status' => 'running']);

    expect(actions(recordingTransport())->request($machine, 'clean_apt_cache', [], $run, 'free disk space'))->toStartWith('PENDING_HUMAN_APPROVAL');
});

it('lets moderate actions through the classifier only when the machine allows them', function () {
    config(['sentinel.actions.restartable_services' => ['nginx']]);
    Classification::fake(jev(0.01, 0.99, 0.97, 'execute', 0.99));
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true, 'autonomy_medium' => true]);

    expect(actions($transport)->request($machine, 'restart_service', ['service' => 'nginx'], null, 'x'))->toBe('done');
});

it('ignores the moderate setting when autonomy itself is off', function () {
    config(['sentinel.actions.restartable_services' => ['nginx']]);
    $machine = Machine::factory()->create(['autonomy_enabled' => false, 'autonomy_medium' => true]);

    expect(actions(recordingTransport())->request($machine, 'restart_service', ['service' => 'nginx'], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL');
});
