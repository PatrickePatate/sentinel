<?php

use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\ActionCatalog;
use App\Ssh\ActionExecutor;
use App\Ssh\AuditTrail;
use App\Ssh\CommandResult;
use App\Ssh\Gate\RiskGate;
use App\Ssh\SshTransport;
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
    $transport = recordingTransport();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);
    $executor = actions($transport);

    expect($executor->request($machine, 'update_package', ['package' => 'curl'], null, 'patch curl'))->toStartWith('PENDING_HUMAN_APPROVAL')
        ->and($transport->commands)->toBeEmpty();

    $executor->approve(PendingAction::first());

    expect($transport->commands[0])->toBe("env DEBIAN_FRONTEND=noninteractive apt-get install --only-upgrade --no-remove -y -o Dpkg::Options::=--force-confold -- 'curl' 2>&1");
});

it('rejects malicious or protected package names', function (string $package) {
    $transport = recordingTransport();

    expect(actions($transport)->request(Machine::factory()->create(), 'update_package', ['package' => $package], null, 'x'))->toStartWith('ERROR')
        ->and($transport->commands)->toBeEmpty()
        ->and(PendingAction::count())->toBe(0);
})->with(['curl; reboot', '$(id)', '--purge', 'Curl', 'a b', '', 'openssh-server', 'libc6', 'linux-image-6.8.0', 'systemd-sysv', 'mysql-server']);

it('prefixes actions with non-interactive sudo when enabled', function () {
    config(['sentinel.actions.use_sudo' => true, 'sentinel.actions.restartable_services' => ['nginx']]);

    expect(ActionCatalog::default()->get('clean_apt_cache')->command([]))->toStartWith('sudo -n apt-get clean')
        ->and(ActionCatalog::default()->get('restart_service')->command(['service' => 'nginx']))->toStartWith('sudo -n systemctl restart')
        ->and(ActionCatalog::default()->get('update_package')->command(['package' => 'curl']))->toStartWith('sudo -n env DEBIAN_FRONTEND');
});
