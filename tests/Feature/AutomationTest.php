<?php

use App\Ai\ScanRunner;
use App\Ai\Tools\MachineHistoryTool;
use App\Ai\Tools\SuggestMemoryNoteTool;
use App\Jobs\RunScan;
use App\Livewire\Actions\Index as ActionsIndex;
use App\Livewire\Machines\Form;
use App\Livewire\Machines\Show;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\MachineMetric;
use App\Models\MemorySuggestion;
use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Models\SiteCheck;
use App\Models\User;
use App\Monitoring\CertificateReader;
use App\Monitoring\SiteMonitor;
use App\Notifications\MachineAlertNotification;
use App\Notifications\Notifier;
use App\Notifications\ScanReportNotification;
use App\Ssh\ActionCatalog;
use App\Ssh\ActionExecutor;
use App\Ssh\AuditTrail;
use App\Ssh\CommandResult;
use App\Ssh\Gate\RiskGate;
use App\Ssh\Probe\WebstackPrecheck;
use App\Ssh\SshTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function sure(): void
{
    Classification::fake([[
        'destructive' => new BooleanAnswer(0.01), 'reversible' => new BooleanAnswer(0.99), 'matches_objective' => new BooleanAnswer(0.98),
        'verdict' => new ChoiceAnswer('execute', ['execute' => 0.99, 'ask_human' => 0.005, 'refuse' => 0.005], 0.99),
    ]]);
}

function recorder(): object
{
    return new class implements SshTransport
    {
        public array $commands = [];

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult('ok', 0);
        }
    };
}

function exec_(object $transport): ActionExecutor
{
    return new ActionExecutor(ActionCatalog::default(), $transport, new RiskGate, new AuditTrail);
}

it('holds an action that keeps being run, instead of repeating it forever', function () {
    $transport = recorder();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);

    foreach (range(1, 3) as $i) {
        sure();
        expect(exec_($transport)->request($machine, 'start_crashed_service', ['service' => 'redis-server'], null, 'x'))->toStartWith('ok');
    }

    sure();
    expect(exec_($transport)->request($machine, 'start_crashed_service', ['service' => 'redis-server'], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL')
        ->and(PendingAction::first()->reason)->toContain('Flapping')
        // three runs of the action itself, each followed by its is-active check
        ->and(collect($transport->commands)->reject(fn ($c) => str_contains($c, 'is-active')))->toHaveCount(3);

    // Another service is a different command: not affected.
    sure();
    expect(exec_($transport)->request($machine, 'start_crashed_service', ['service' => 'nginx'], null, 'x'))->toStartWith('ok');
});

it('tells the agent what already ran lately', function () {
    $transport = recorder();
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);
    sure();
    exec_($transport)->request($machine, 'start_crashed_service', ['service' => 'redis-server'], null, 'x');
    MachineMetric::create(['machine_id' => $machine->id, 'name' => 'disk_root_percent', 'value' => 50, 'recorded_at' => now()->subDays(2)]);
    MachineMetric::create(['machine_id' => $machine->id, 'name' => 'disk_root_percent', 'value' => 60, 'recorded_at' => now()]);

    $out = (string) (new MachineHistoryTool($machine))->handle(new Request([]));

    expect($out)->toContain('1x', 'sentinel-service-recover', '+5.0 points per day', 'full in about 8 days');
});

it('uses the per-machine gate settings, and bounds them', function () {
    $transport = recorder();
    $machine = Machine::factory()->create(['autonomy_enabled' => true, 'gate_max_destructive' => 0.0, 'gate_max_actions' => 1]);
    Classification::fake([[
        'destructive' => new BooleanAnswer(0.03), 'reversible' => new BooleanAnswer(0.99), 'matches_objective' => new BooleanAnswer(0.98),
        'verdict' => new ChoiceAnswer('execute', ['execute' => 0.99, 'ask_human' => 0.005, 'refuse' => 0.005], 0.99),
    ]]);

    // 0.03 would pass the global 0.05 limit, but this machine is stricter.
    expect(exec_($transport)->request($machine, 'clean_apt_cache', [], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL');

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(Form::class)->set(['name' => 'a', 'host' => '10.0.0.1', 'gate_max_destructive' => '0.9'])->call('save')->assertHasErrors('gate_max_destructive');
    Livewire::test(Form::class)->set(['name' => 'a', 'host' => '10.0.0.1', 'gate_max_destructive' => '0.01', 'gate_min_reversible' => '0.9', 'gate_max_actions' => '2'])->call('save')->assertHasNoErrors();
    $saved = Machine::firstWhere('name', 'a');
    expect($saved->gate('max_destructive'))->toBe(0.01)->and($saved->gate('max_actions'))->toBe(2);
});

it('runs a trusted action without asking, but never a high-risk or unsafeguarded one, and revokes', function () {
    $transport = recorder();
    $machine = Machine::factory()->create(['autonomy_enabled' => false, 'trusted_actions' => ['rollback_web_config', 'restart_service', 'clean_apt_cache']]);
    config(['sentinel.actions.restartable_services' => ['nginx']]);

    expect(exec_($transport)->request($machine, 'rollback_web_config', ['service' => 'nginx'], null, 'x'))->toBe("ok\nVERIFIED: the nginx configuration passes its test.")
        // trusted names without declared safeguards are ignored: they go through the normal rules
        ->and(exec_($transport)->request($machine, 'restart_service', ['service' => 'nginx'], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL')
        ->and(exec_($transport)->request($machine, 'clean_apt_cache', [], null, 'x'))->toStartWith('PENDING_HUMAN_APPROVAL');

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(Show::class, ['machine' => $machine])->call('revokeTrust', 'rollback_web_config');
    expect($machine->fresh()->trusts('rollback_web_config'))->toBeFalse();
});

it('trusts an action from the approvals page when asked, only if it has safeguards', function () {
    $transport = recorder();
    app()->instance(SshTransport::class, $transport);
    $machine = Machine::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    $make = fn (string $action, array $args, string $command) => PendingAction::create(['machine_id' => $machine->id, 'action' => $action, 'arguments' => $args, 'command' => $command, 'risk' => 'low', 'reason' => 'x']);
    $rollback = $make('rollback_web_config', ['service' => 'nginx'], ActionCatalog::default()->get('rollback_web_config')->command(['service' => 'nginx']));
    $apt = $make('clean_apt_cache', [], ActionCatalog::default()->get('clean_apt_cache')->command([]));

    Livewire::test(ActionsIndex::class)->call('approve', $rollback->id, true)->call('approve', $apt->id, true);

    expect($machine->fresh()->trusted_actions)->toBe(['rollback_web_config']);
});

it('lets the agent suggest memory notes that an admin accepts or dismisses', function () {
    $machine = Machine::factory()->create(['memory' => 'nginx and mysql']);
    $tool = new SuggestMemoryNoteTool($machine, null);

    expect((string) $tool->handle(new Request(['note'])))->toStartWith('ERROR')
        ->and((string) $tool->handle(new Request(['note' => 'It also runs redis on 6379'])))->toStartWith('SUGGESTED')
        ->and((string) $tool->handle(new Request(['note' => 'it also runs redis on 6379'])))->toStartWith('ALREADY_SUGGESTED')
        ->and((string) $tool->handle(new Request(['note' => 'Nginx and MySQL'])))->toStartWith('ALREADY_KNOWN');

    foreach (range(1, 6) as $i) {
        $tool->handle(new Request(['note' => "fact {$i}"]));
    }
    expect(MemorySuggestion::where('status', 'pending')->count())->toBe(SuggestMemoryNoteTool::MAX_PENDING);

    $this->actingAs(User::factory()->admin()->create());
    $notes = MemorySuggestion::orderBy('id')->get();
    Livewire::test(Show::class, ['machine' => $machine])->assertSee('It also runs redis on 6379')->call('acceptNote', $notes[0]->id)->call('dismissNote', $notes[1]->id);

    expect($machine->fresh()->memory)->toBe("nginx and mysql\n- It also runs redis on 6379")
        ->and($notes[0]->fresh()->status)->toBe('accepted')->and($notes[1]->fresh()->status)->toBe('dismissed');
});

describe('web stack precheck', function () {
    it('reads a healthy stack and a broken one', function () {
        $probe = app(WebstackPrecheck::class);

        $ok = $probe->interpret("disk 41\nunit nginx.service active enabled\nunit apache2.service inactive disabled\nconfig nginx ok\nhttp 200\n");
        expect($ok['healthy'])->toBeTrue()->and($ok['disk'])->toBe(41.0);

        foreach (['unit redis-server.service inactive enabled', 'unit mysql.service failed enabled', 'config nginx fail', "unit nginx.service active enabled\nhttp 502", "unit nginx.service active enabled\nhttp 000", ''] as $output) {
            expect($probe->interpret($output)['healthy'])->toBeFalse($output);
        }
    });

    it('closes a healthy scheduled check without calling the model, except for the periodic full check', function () {
        Notification::fake();
        $transport = new class implements SshTransport
        {
            public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
            {
                return new CommandResult("disk 40\nunit nginx.service active enabled\nconfig nginx ok\nhttp 200\n", 0);
            }
        };
        app()->instance(SshTransport::class, $transport);
        $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a']);

        $first = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'anthropic', 'objective' => 'x', 'status' => 'queued', 'profile' => 'webserver', 'trigger' => 'scheduled']);
        // No full AI check yet in the last hours: this one must go to the model.
        expect((new ReflectionMethod(RunScan::class, 'precheckClears'))->invoke(new RunScan($machine->id, 'x', null, 'scheduled', $first->id, 'webserver')))->toBeFalse();

        AgentRun::whereKey($first->id)->update(['status' => 'completed']);
        $second = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'anthropic', 'objective' => 'x', 'status' => 'queued', 'profile' => 'webserver', 'trigger' => 'scheduled']);
        (new RunScan($machine->id, 'x', null, 'scheduled', $second->id, 'webserver'))->handle(app(ScanRunner::class));

        $second->refresh();
        expect($second->provider)->toBe('precheck')->and($second->status)->toBe('completed')->and($second->severity)->toBe('none')
            ->and($second->report)->toContain('nginx.service: active')
            ->and(MachineMetric::where('machine_id', $machine->id)->value('value'))->toBe(40.0);
    });
});

describe('site monitoring', function () {
    function alertChannel(): NotificationChannel
    {
        return NotificationChannel::create(['name' => 'ops', 'type' => 'mail', 'settings' => ['email' => 'ops@example.org'], 'min_severity' => 'critical', 'notify_scans' => true, 'notify_approvals' => true, 'enabled' => true]);
    }

    it('alerts once when a site stays down, starts a web server check, and announces the recovery', function () {
        Notification::fake();
        Queue::fake();
        alertChannel();
        $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a', 'webserver_enabled' => true]);
        SiteCheck::create(['machine_id' => $machine->id, 'url' => 'http://shop.test']);
        $this->mock(CertificateReader::class)->shouldReceive('expiry')->andReturn(null);
        $monitor = app(SiteMonitor::class);

        $status = 502;
        Http::fake(['shop.test' => function () use (&$status) {
            return Http::response('page', $status);
        }]);
        $monitor->checkAll();
        Notification::assertNothingSent(); // one failure is not an outage

        $monitor->checkAll();
        $monitor->checkAll();
        Notification::assertSentTimes(MachineAlertNotification::class, 1);
        Queue::assertPushed(RunScan::class, 1);
        Queue::assertPushed(RunScan::class, fn (RunScan $job) => $job->profile === 'webserver' && $job->trigger === 'site_down');
        expect(SiteCheck::first()->ok)->toBeFalse()->and(SiteCheck::first()->failures)->toBe(3);

        $status = 200;
        $monitor->checkAll();
        Notification::assertSentTimes(MachineAlertNotification::class, 2);
        expect(SiteCheck::first()->ok)->toBeTrue()->and(SiteCheck::first()->alerted)->toBeNull();
    });

    it('warns once about a certificate that is about to expire', function () {
        Notification::fake();
        alertChannel();
        $machine = Machine::factory()->create();
        SiteCheck::create(['machine_id' => $machine->id, 'url' => 'https://shop.test']);
        $this->mock(CertificateReader::class)->shouldReceive('expiry')->andReturn(now()->addDays(5)->toImmutable());
        Http::fake(['shop.test' => Http::response('ok', 200)]);

        app(SiteMonitor::class)->checkAll();
        app(SiteMonitor::class)->checkAll();

        Notification::assertSentTimes(MachineAlertNotification::class, 1);
    });

    it('ignores the sites of revoked machines', function () {
        Http::fake();
        $this->mock(CertificateReader::class)->shouldReceive('expiry')->andReturn(null);
        $active = Machine::factory()->create();
        $revoked = Machine::factory()->create(['revoked_at' => now()]);
        SiteCheck::create(['machine_id' => $active->id, 'url' => 'http://a.test']);
        SiteCheck::create(['machine_id' => $revoked->id, 'url' => 'http://c.test']);

        expect(app(SiteMonitor::class)->checkAll())->toBe(1);
    });

    it('lets a site opt out of starting an analysis', function () {
        Notification::fake();
        Queue::fake();
        alertChannel();
        $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a', 'webserver_enabled' => true]);
        SiteCheck::create(['machine_id' => $machine->id, 'url' => 'http://shop.test', 'analyze_on_down' => false]);
        $this->mock(CertificateReader::class)->shouldReceive('expiry')->andReturn(null);
        Http::fake(['shop.test' => Http::response('boom', 502)]);

        app(SiteMonitor::class)->checkAll();
        app(SiteMonitor::class)->checkAll();

        Notification::assertSentTimes(MachineAlertNotification::class, 1);
        Queue::assertNothingPushed();
    });
});

it('sends an urgent notification for a web server check that leaves something down, whatever the channel minimum', function () {
    Notification::fake();
    NotificationChannel::create(['name' => 'ops', 'type' => 'mail', 'settings' => ['email' => 'ops@example.org'], 'min_severity' => 'critical', 'notify_scans' => true, 'notify_approvals' => true, 'enabled' => true]);
    $machine = Machine::factory()->create();
    $make = fn (string $trigger, string $profile, string $severity) => AgentRun::create(['machine_id' => $machine->id, 'provider' => 'x', 'objective' => 'x', 'status' => 'completed', 'trigger' => $trigger, 'profile' => $profile, 'severity' => $severity]);

    $notifier = app(Notifier::class);
    $notifier->scanFinished($make('scheduled', 'audit', 'high')->load('machine'));
    Notification::assertNothingSent(); // an audit finding below the channel minimum stays quiet

    $notifier->scanFinished($make('scheduled', 'webserver', 'high')->load('machine'));
    Notification::assertSentTimes(ScanReportNotification::class, 1);
    Notification::assertSentTo(NotificationChannel::first(), ScanReportNotification::class, fn ($n) => $n->urgent);
});

it('does not schedule, trigger or run anything web server related on a machine where it is off', function () {
    Notification::fake();
    Queue::fake();
    alertChannel();
    Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a', 'webserver_enabled' => false, 'webserver_interval_minutes' => 5]);
    SiteCheck::create(['machine_id' => Machine::latest('id')->value('id'), 'url' => 'http://shop.test']);
    $this->mock(CertificateReader::class)->shouldReceive('expiry')->andReturn(null);
    Http::fake(['shop.test' => Http::response('boom', 502)]);

    $this->artisan('sentinel:scan-due')->assertSuccessful();
    foreach (range(1, 3) as $i) {
        app(SiteMonitor::class)->checkAll();
    }

    Queue::assertNothingPushed();
    Notification::assertSentTimes(MachineAlertNotification::class, 1); // the site alert itself still goes out
});

it('only calls the model when the quick check finds a problem if the machine says so', function () {
    Notification::fake();
    $transport = new class implements SshTransport
    {
        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            return new CommandResult("disk 40\nunit nginx.service active enabled\nconfig nginx ok\nhttp 200\n", 0);
        }
    };
    app()->instance(SshTransport::class, $transport);
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a', 'webserver_enabled' => true, 'webserver_full_check_hours' => 0]);
    $run = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'anthropic', 'objective' => 'x', 'status' => 'queued', 'profile' => 'webserver', 'trigger' => 'scheduled']);

    (new RunScan($machine->id, 'x', null, 'scheduled', $run->id, 'webserver'))->handle(app(ScanRunner::class));

    expect($run->fresh()->provider)->toBe('precheck')->and($machine->fullCheckHours())->toBe(0);
});
