<?php

use App\Ai\Agents\SysadminAgent;
use App\Jobs\RunScan;
use App\Livewire\Machines\Form;
use App\Livewire\Machines\Show;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\User;
use App\Ssh\ActionCatalog;
use App\Ssh\ActionExecutor;
use App\Ssh\AuditTrail;
use App\Ssh\Fake\FakeTransport;
use App\Ssh\Gate\RiskGate;
use App\Ssh\Provisioning\ClientBundle;
use App\Ssh\Tools\HttpProbeTool;
use App\Ssh\Tools\InvalidToolArguments;
use App\Ssh\Tools\WebConfigTestTool;
use App\Ssh\Tools\WebErrorLogsTool;
use Database\Seeders\FakeMachinesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function confidentGate(): void
{
    Classification::fake([[
        'destructive' => new BooleanAnswer(0.01),
        'reversible' => new BooleanAnswer(0.99),
        'matches_objective' => new BooleanAnswer(0.98),
        'verdict' => new ChoiceAnswer('execute', ['execute' => 0.99, 'ask_human' => 0.005, 'refuse' => 0.005], 0.99),
    ]]);
}

function fakeWebMachine(string $name = 'app-prod-02'): Machine
{
    config(['sentinel.transport' => 'fake', 'sentinel.fake.latency_ms' => 0]);
    test()->seed(FakeMachinesSeeder::class);

    return Machine::where('name', $name)->first();
}

function fakeExecutor(): ActionExecutor
{
    return new ActionExecutor(ActionCatalog::default(), new FakeTransport, new RiskGate, new AuditTrail);
}

function setFakeState(Machine $machine, callable $change): void
{
    $state = (new FakeTransport)->state($machine);
    Cache::forever("sentinel.fake.{$machine->id}.{$machine->host}", $change($state));
}

it('only builds web stack commands from validated arguments', function () {
    expect((new WebConfigTestTool)->command(['service' => 'nginx']))->toContain("sentinel-web-config test 'nginx'")
        ->and((new WebErrorLogsTool)->command(['component' => 'mysql', 'lines' => 500]))->toContain('tail -n 200')
        ->and((new HttpProbeTool)->command(['host' => 'shop.example.org', 'path' => '/health?x=1', 'https' => true]))
        ->toContain("'https://127.0.0.1/health?x=1'")->toContain("'Host: shop.example.org'");

    foreach ([
        fn () => (new WebConfigTestTool)->command(['service' => 'nginx; id']),
        fn () => (new WebErrorLogsTool)->command(['component' => '../../etc/shadow']),
        fn () => (new WebErrorLogsTool)->command(['component' => 'nginx', 'lines' => '5; id']),
        fn () => (new HttpProbeTool)->command(['host' => "a.org' -H 'X: y"]),
        fn () => (new HttpProbeTool)->command(['path' => 'http://169.254.169.254/']),
        fn () => (new HttpProbeTool)->command(['path' => '/x y']),
    ] as $attempt) {
        expect($attempt)->toThrow(InvalidToolArguments::class);
    }
});

it('ships the web stack wrappers in the client bundle, with the allowlist filled in and a sudo rule', function () {
    $bundle = app(ClientBundle::class);
    $wrappers = $bundle->wrappers();

    expect($wrappers)->toHaveKeys(['sentinel-service-recover', 'sentinel-web-config'])
        ->and($wrappers['sentinel-service-recover'])->toContain("'nginx'", "'php*-fpm'")->not->toContain('__RECOVERABLE__')
        ->and($bundle->sudoers('sentinel'))->toContain('/usr/local/sbin/sentinel-service-recover *', '/usr/local/sbin/sentinel-web-config *');

    foreach ($wrappers as $name => $body) {
        $file = tempnam(sys_get_temp_dir(), 'w');
        file_put_contents($file, $body);
        expect(Process::run(['bash', '-n', $file])->successful())->toBeTrue($name);
        unlink($file);
    }
});

it('lets extra recoverable services be configured and nothing else', function () {
    config(['sentinel.actions.recoverable_services' => ['nginx', 'my-app']]);

    $wrapper = app(ClientBundle::class)->wrappers()['sentinel-service-recover'];

    expect($wrapper)->toContain("'my-app'")->not->toContain("'mysql'");
});

it('starts a crashed service on its own when the gate agrees, and leaves running ones alone', function () {
    confidentGate();
    $machine = fakeWebMachine();
    $machine->update(['autonomy_enabled' => true]);

    $out = fakeExecutor()->request($machine, 'start_crashed_service', ['service' => 'php8.3-fpm'], null, 'check the web stack');
    expect($out)->toContain('is now: active');

    confidentGate();
    expect(fakeExecutor()->request($machine, 'start_crashed_service', ['service' => 'nginx'], null, 'x'))->toContain('already running');
});

it('does not start a service that is not a web stack unit', function () {
    confidentGate();
    $machine = fakeWebMachine();
    $machine->update(['autonomy_enabled' => true]);

    expect(fakeExecutor()->request($machine, 'start_crashed_service', ['service' => 'ssh'], null, 'x'))->toContain('not on the recoverable allowlist');
});

it('asks a human to start a service when autonomy is off', function () {
    $machine = fakeWebMachine();

    expect(fakeExecutor()->request($machine, 'start_crashed_service', ['service' => 'php8.3-fpm'], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL');
});

it('rolls a broken web server configuration back, then starts the server', function () {
    confidentGate();
    $machine = fakeWebMachine();
    $machine->update(['autonomy_enabled' => true]);
    setFakeState($machine, function (array $s) {
        $s['services']['nginx']['state'] = 'failed';
        $s['web_config']['nginx'] = ['valid' => false, 'good' => true];

        return $s;
    });

    // The wrapper refuses to start a server with a configuration that does not pass its test.
    expect(fakeExecutor()->request($machine, 'start_crashed_service', ['service' => 'nginx'], null, 'x'))->toContain('configuration is invalid');

    confidentGate();
    expect(fakeExecutor()->request($machine, 'rollback_web_config', ['service' => 'nginx'], null, 'x'))->toContain('restored the last known good');

    confidentGate();
    expect(fakeExecutor()->request($machine, 'start_crashed_service', ['service' => 'nginx'], null, 'x'))->toContain('is now: active');
});

it('refuses to roll back when no good configuration was recorded, and does not reload an invalid one', function () {
    confidentGate();
    $machine = fakeWebMachine();
    $machine->update(['autonomy_enabled' => true]);
    setFakeState($machine, function (array $s) {
        $s['web_config']['nginx'] = ['valid' => false, 'good' => false];

        return $s;
    });

    expect(fakeExecutor()->request($machine, 'rollback_web_config', ['service' => 'nginx'], null, 'x'))->toContain('cannot roll back');

    confidentGate();
    expect(fakeExecutor()->request($machine, 'reload_web_config', ['service' => 'nginx'], null, 'x'))->toContain('NOT reloading');
});

it('puts the administrator notes and the scan profile instructions in the agent prompt', function () {
    $machine = Machine::factory()->create(['memory' => 'Shop: nginx, php8.3-fpm, mysql and redis.']);
    $webserver = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'anthropic', 'objective' => 'x', 'status' => 'running', 'profile' => 'webserver']);
    $audit = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'anthropic', 'objective' => 'x', 'status' => 'running', 'profile' => 'audit']);

    $prompt = (string) (new SysadminAgent($machine, $webserver, requiresVerdict: true))->instructions();
    expect($prompt)->toContain('<administrator_notes>', 'Shop: nginx, php8.3-fpm, mysql and redis.', 'WEB SERVER HEALTH CHECK', 'rollback_web_config');

    $plain = (string) (new SysadminAgent($machine, $audit, requiresVerdict: true))->instructions();
    expect($plain)->toContain('Shop: nginx')->not->toContain('WEB SERVER HEALTH CHECK');

    expect((string) (new SysadminAgent(Machine::factory()->create()))->instructions())->not->toContain('administrator_notes');
});

it('offers the web stack tools and actions to the agent', function () {
    $names = collect(iterator_to_array((new SysadminAgent(Machine::factory()->create()))->tools(), false))->map->name()->all();

    expect($names)->toContain('webstack_overview', 'database_health', 'web_config_test', 'web_error_logs', 'http_probe', 'start_crashed_service', 'reload_web_config', 'rollback_web_config')
        ->toContain('propose_start_crashed_service');
});

it('schedules the web server check separately from the audit', function () {
    Queue::fake();
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a', 'scan_interval_minutes' => null, 'webserver_enabled' => true, 'webserver_interval_minutes' => 15]);

    $this->artisan('sentinel:scan-due')->assertSuccessful();
    $this->artisan('sentinel:scan-due')->assertSuccessful();

    Queue::assertPushed(RunScan::class, 1);
    Queue::assertPushed(RunScan::class, fn (RunScan $job) => $job->machineId === $machine->id && $job->profile === 'webserver' && str_contains($job->objective, 'web stack'));
    expect(AgentRun::first()->profile)->toBe('webserver')->and(AgentRun::first()->trigger)->toBe('scheduled')
        ->and($machine->fresh()->last_webserver_scan_at)->not->toBeNull()->and($machine->fresh()->last_scan_at)->toBeNull();

    AgentRun::query()->update(['status' => 'completed']);
    $this->travel(16)->minutes();
    $this->artisan('sentinel:scan-due')->assertSuccessful();
    Queue::assertPushed(RunScan::class, 2);
});

describe('dashboard', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('saves the machine memory and the web server schedule, and validates the frequency', function () {
        $fill = fn ($c, array $extra) => $c->set(['name' => 'shop', 'host' => '10.0.0.9'] + $extra)->call('save');

        $fill(Livewire::test(Form::class), ['memory' => "  Web server: nginx, redis.  \n", 'webserver_enabled' => true, 'webserver_interval_minutes' => 30])->assertHasNoErrors();
        $machine = Machine::firstWhere('name', 'shop');
        expect($machine->memory)->toBe('Web server: nginx, redis.')->and($machine->webserver_interval_minutes)->toBe(30);

        Livewire::test(Form::class, ['machine' => $machine])->assertSet('memory', 'Web server: nginx, redis.');

        $fill(Livewire::test(Form::class, ['machine' => $machine]), ['memory' => '', 'webserver_interval_minutes' => 0])->assertHasNoErrors();
        expect($machine->fresh()->memory)->toBeNull()->and($machine->fresh()->webserver_interval_minutes)->toBeNull();

        $fill(Livewire::test(Form::class, ['machine' => $machine]), ['webserver_interval_minutes' => 7])->assertHasErrors('webserver_interval_minutes');
        $fill(Livewire::test(Form::class, ['machine' => $machine]), ['memory' => str_repeat('a', 3001)])->assertHasErrors('memory');
    });

    it('runs a web server scan from the scan modal, with the objective preset by the type', function () {
        Queue::fake();
        $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a', 'memory' => 'nginx and mysql', 'webserver_enabled' => true]);

        Livewire::test(Show::class, ['machine' => $machine])
            ->assertSee('nginx and mysql')
            ->set('profile', 'webserver')
            ->assertSet('objective', config('sentinel.scheduling.profiles.webserver.objective'))
            ->call('scan');

        Queue::assertPushed(RunScan::class, fn (RunScan $job) => $job->profile === 'webserver' && $job->trigger === 'manual');
        expect(AgentRun::first()->profile)->toBe('webserver')->and(AgentRun::first()->profileLabel())->toBe('Web server check');
    });

    it('rejects an unknown scan type', function () {
        Queue::fake();
        $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a']);

        Livewire::test(Show::class, ['machine' => $machine])->set('profile', 'nonsense')->call('scan')->assertHasErrors('profile');
        Queue::assertNothingPushed();
    });

    it('keeps the web server analysis off until an admin enables it', function () {
        Queue::fake();
        $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a']);

        expect($machine->fresh()->webserver_enabled)->toBeFalse();

        $component = Livewire::test(Show::class, ['machine' => $machine]);
        expect(array_keys($component->instance()->scanTypes()))->toBe(['audit']);
        $component->set('profile', 'webserver')->call('scan')->assertHasErrors('profile');
        Queue::assertNothingPushed();

        Machine::whereKey($machine->id)->update(['webserver_enabled' => true]);
        expect(array_keys(Livewire::test(Show::class, ['machine' => $machine])->instance()->scanTypes()))->toBe(['audit', 'webserver']);
    });

    it('saves the web server analysis switch, the quick check frequency and the AI check frequency', function () {
        $fill = fn ($c, array $extra) => $c->set(['name' => 'shop', 'host' => '10.0.0.9'] + $extra)->call('save');

        $fill(Livewire::test(Form::class), ['webserver_enabled' => true, 'webserver_interval_minutes' => 5, 'webserver_full_check_hours' => 0])->assertHasNoErrors();
        $machine = Machine::firstWhere('name', 'shop');
        expect($machine->webserver_enabled)->toBeTrue()->and($machine->webserver_interval_minutes)->toBe(5)->and($machine->fullCheckHours())->toBe(0);

        Livewire::test(Form::class, ['machine' => $machine])->assertSet('webserver_enabled', true)->assertSet('webserver_full_check_hours', 0);
        $fill(Livewire::test(Form::class, ['machine' => $machine]), ['webserver_full_check_hours' => 5])->assertHasErrors('webserver_full_check_hours');
    });
});
