<?php

use App\Jobs\RunScan;
use App\Livewire\Actions;
use App\Livewire\AuditLog;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Machines;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Models\User;
use App\Ssh\CommandResult;
use App\Ssh\SshTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('logs an admin in, refuses wrong passwords and throttles guessing', function () {
    User::factory()->admin()->create(['email' => 'a@example.org', 'password' => 'correct-horse-battery']);

    Livewire::test(Login::class)->set('email', 'a@example.org')->set('password', 'nope')->call('login')->assertHasErrors('email');
    $this->assertGuest();

    Livewire::test(Login::class)->set('email', 'a@example.org')->set('password', 'correct-horse-battery')->call('login')->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    auth()->logout();
    foreach (range(1, 5) as $i) {
        Livewire::test(Login::class)->set('email', 'b@example.org')->set('password', 'x')->call('login');
    }
    Livewire::test(Login::class)->set('email', 'b@example.org')->set('password', 'x')->call('login')->assertSee('Too many attempts');
});

it('serves every page to an admin and to nobody else', function () {
    $machine = Machine::factory()->create(['private_key' => Machine::generatePrivateKey()]);
    $run = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'x', 'objective' => 'x']);
    $channel = NotificationChannel::create(['name' => 'c', 'type' => 'mail', 'settings' => ['email' => 'a@b.c'], 'min_severity' => 'low']);
    $urls = [
        route('dashboard'), route('machines.index'), route('machines.create'), route('machines.edit', $machine), route('scans.index'),
        route('scans.show', $run), route('actions.index'), route('channels.index'), route('channels.create'), route('channels.edit', $channel), route('audit'),
        ...collect(array_keys(Machines\Show::TABS))->map(fn ($tab) => route('machines.show', ['machine' => $machine, 'tab' => $tab]))->all(),
    ];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->create());
    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }

    $this->actingAs(User::factory()->admin()->create());
    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }
});

it('shows the fleet, what needs attention and what waits for approval', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create(['name' => 'web-1']);
    AgentRun::create(['machine_id' => $machine->id, 'provider' => 'x', 'objective' => 'x', 'status' => 'completed', 'severity' => 'critical', 'summary' => 'Root login open']);
    PendingAction::create(['machine_id' => $machine->id, 'action' => 'restart_service', 'command' => 'systemctl restart -- nginx', 'risk' => 'medium', 'reason' => 'r']);
    activity('ssh')->performedOn($machine)->event('ok')->withProperties(['command' => 'df'])->log('disk_usage');

    Livewire::test(Dashboard::class)
        ->assertSee('web-1')->assertSee('Critical')->assertSee('systemctl restart -- nginx')->assertSee('disk_usage')
        ->assertSeeHtml('wire:poll.5s');
});

it('creates a machine with a Sentinel-generated user and key (the form has no field for them)', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(Machines\Form::class)
        ->set('name', 'db-1')->set('host', '2a01:4f8:120:643c::107')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    $machine = Machine::firstWhere('name', 'db-1');
    expect($machine->username)->toBe('sentinel')
        ->and($machine->private_key)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($machine->publicKey())->toStartWith('ssh-ed25519 ')
        ->and(DB::table('machines')->value('private_key'))->not->toContain('OPENSSH');

    $key = $machine->private_key;
    Livewire::test(Machines\Form::class, ['machine' => $machine])->set('host', '10.0.0.6')->call('save')->assertHasNoErrors();

    expect($machine->fresh()->private_key)->toBe($key)->and($machine->fresh()->username)->toBe('sentinel');
});

it('resets the pinned host key when the address changes', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create(['host' => '10.0.0.1', 'host_key_fingerprint' => 'SHA256:abc']);

    Livewire::test(Machines\Form::class, ['machine' => $machine])->set('host', '10.0.0.2')->call('save')->assertHasNoErrors();
    expect($machine->fresh()->host_key_fingerprint)->toBeNull();

    Machine::whereKey($machine->id)->update(['host_key_fingerprint' => 'SHA256:abc']);
    Livewire::test(Machines\Form::class, ['machine' => $machine->fresh()])->set('name', 'renamed')->call('save');
    expect($machine->fresh()->host_key_fingerprint)->toBe('SHA256:abc');
});

it('queues a scan with the objective typed in the dashboard', function () {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();

    Livewire::test(Machines\Index::class)->call('startScan', $machine->id)->set('objective', 'check ssh hardening')->call('scan')->assertRedirect();

    $run = AgentRun::firstWhere('machine_id', $machine->id);
    expect($run->status)->toBe('queued')->and($run->objective)->toBe('check ssh hardening');
    Queue::assertPushed(RunScan::class, fn ($job) => $job->runId === $run->id && $job->objective === 'check ssh hardening');
});

it('approves a pending action and runs it, or rejects it without running anything', function () {
    $this->actingAs(User::factory()->admin()->create());
    $transport = new class implements SshTransport
    {
        public array $commands = [];

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult('ok', 0);
        }
    };
    app()->instance(SshTransport::class, $transport);
    $machine = Machine::factory()->create();
    $make = fn () => PendingAction::create(['machine_id' => $machine->id, 'action' => 'clean_apt_cache', 'arguments' => [], 'command' => 'apt-get clean 2>&1', 'risk' => 'low', 'reason' => 'test']);
    [$run, $refuse] = [$make(), $make()];

    Livewire::test(Actions\Index::class)->call('approve', $run->id)->call('reject', $refuse->id);

    expect($transport->commands)->toBe(['apt-get clean 2>&1'])->and($run->fresh()->status)->toBe('executed')->and($refuse->fresh()->status)->toBe('rejected');

    // A decided action cannot be run a second time.
    Livewire::test(Actions\Index::class)->call('approve', $run->id);
    expect($transport->commands)->toHaveCount(1);
});

it('filters and searches the audit log', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();
    activity('ssh')->performedOn($machine)->event('ok')->withProperties(['command' => 'df'])->log('disk_usage');
    activity('ssh')->performedOn($machine)->event('ok')->withProperties(['command' => 'free'])->log('memory_usage');

    Livewire::test(AuditLog::class)->assertSee('disk_usage')->assertSee('memory_usage')
        ->set('search', 'disk')->assertSee('disk_usage')->assertDontSee('memory_usage');
    expect(Activity::count())->toBe(2);
});

it('hides secrets from the machine pages', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create(['private_key' => Machine::generatePrivateKey()]);

    $this->get(route('machines.show', ['machine' => $machine, 'tab' => 'provisioning']))->assertOk()
        ->assertDontSee('PRIVATE KEY')->assertSee('ssh-ed25519');
});

it('never leaves a Blade directive inside a component tag, where it would be printed as text', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
        ->filter(fn ($file) => preg_match('/<x-[a-z.:-]+\s[^>]*?(?<![\w@])@(?:if|else|endif|foreach|realtime|endrealtime|php|js|class)\b/s', file_get_contents($file->getPathname())))
        ->map(fn ($file) => $file->getRelativePathname());

    expect($offenders->all())->toBe([]);
});
