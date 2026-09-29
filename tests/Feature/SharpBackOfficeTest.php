<?php

use App\Jobs\RunScan;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Models\User;
use App\Sharp\Entities\AgentRunEntity;
use App\Sharp\Entities\AuditEntryEntity;
use App\Sharp\Entities\MachineEntity;
use App\Sharp\Entities\PendingActionEntity;
use App\Sharp\Machines\DownloadProvisionScriptCommand;
use App\Sharp\Machines\ScanMachineCommand;
use App\Sharp\PendingActions\ApprovePendingActionCommand;
use App\Sharp\PendingActions\RejectPendingActionCommand;
use App\Sharp\SharpMenu;
use App\Ssh\CommandResult;
use App\Ssh\SshTransport;
use Code16\Sharp\Utils\Menu\SharpMenuItemSection;
use Code16\Sharp\Utils\Testing\SharpAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, SharpAssertions::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('lists machines without exposing secrets', function () {
    Machine::factory()->create(['name' => 'web-1']);

    $this->sharpList(MachineEntity::class)->get()->assertListData(fn ($data) => $data->count(1)->where('0.name', 'web-1')->missing('0.private_key')->etc());
});

it('creates a machine with a Sentinel-generated user and key, never asking for them', function () {
    $this->sharpForm(MachineEntity::class)->store([
        'name' => 'db-1', 'host' => '10.0.0.5', 'port' => 22, 'environment' => 'production', 'autonomy_enabled' => false,
        'username' => 'root', 'private_key' => 'ATTACKER-KEY', // ignored: not form fields any more
    ])->assertSessionHasNoErrors()->assertRedirect();

    $machine = Machine::firstWhere('name', 'db-1');
    expect($machine->username)->toBe('sentinel')
        ->and($machine->private_key)->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and($machine->publicKey())->toStartWith('ssh-ed25519 ')
        ->and(DB::table('machines')->value('private_key'))->not->toContain('OPENSSH');

    $key = $machine->private_key;

    $this->sharpForm(MachineEntity::class, $machine->id)->update([
        'name' => 'db-1', 'host' => '10.0.0.6', 'port' => 22, 'environment' => 'production', 'autonomy_enabled' => false,
        'username' => 'root', 'private_key' => 'ATTACKER-KEY',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($machine->fresh()->private_key)->toBe($key)->and($machine->fresh()->username)->toBe('sentinel');
});

it('gives each new machine its own key', function () {
    foreach (['a', 'b'] as $name) {
        $this->sharpForm(MachineEntity::class)->store(['name' => $name, 'host' => '10.0.0.5', 'port' => 22, 'environment' => 'staging', 'autonomy_enabled' => false]);
    }

    expect(Machine::pluck('private_key')->unique())->toHaveCount(2);
});

it('downloads the provisioning script from the machine page and audits it', function () {
    $machine = Machine::factory()->create(['private_key' => Machine::generatePrivateKey()]);

    $this->sharpShow(MachineEntity::class, $machine->id)
        ->instanceCommand(DownloadProvisionScriptCommand::class)
        ->getForm()->post(['from' => '203.0.113.7'])
        ->assertReturnsDownload('sentinel-provision-'.Str::slug($machine->name).'.sh');

    expect(Activity::where('event', 'provision_script_downloaded')->count())->toBe(1);
});

it('resets the pinned host key when the address changes', function () {
    $machine = Machine::factory()->create(['host' => '10.0.0.1', 'host_key_fingerprint' => 'SHA256:abc']);

    $this->sharpForm(MachineEntity::class, $machine->id)->update([
        'name' => $machine->name, 'host' => '10.0.0.2', 'port' => 22, 'username' => 'sentinel',
        'environment' => 'production', 'autonomy_enabled' => false,
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($machine->fresh()->host_key_fingerprint)->toBeNull();
});

it('queues a scan from the back-office', function () {
    Queue::fake();
    $machine = Machine::factory()->create();

    $this->sharpList(MachineEntity::class)
        ->instanceCommand(ScanMachineCommand::class, $machine->id)
        ->getForm()
        ->post(['objective' => 'check ssh hardening'])
        ->assertReturnsInfo();

    Queue::assertPushed(RunScan::class, fn ($job) => $job->machineId === $machine->id && $job->objective === 'check ssh hardening');
});

it('approves a pending action and runs it', function () {
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

    $pending = PendingAction::create([
        'machine_id' => Machine::factory()->create()->id, 'action' => 'clean_apt_cache', 'arguments' => [],
        'command' => 'apt-get clean 2>&1', 'risk' => 'low', 'reason' => 'test',
    ]);

    $this->sharpList(PendingActionEntity::class)
        ->instanceCommand(ApprovePendingActionCommand::class, $pending->id)
        ->post()
        ->assertReturnsRefresh([$pending->id]);

    expect($transport->commands)->toBe(['apt-get clean 2>&1'])->and($pending->fresh()->status)->toBe('executed');
});

it('rejects a pending action without running anything', function () {
    $pending = PendingAction::create([
        'machine_id' => Machine::factory()->create()->id, 'action' => 'clean_apt_cache',
        'command' => 'x', 'risk' => 'low', 'reason' => 'test',
    ]);

    $this->sharpList(PendingActionEntity::class)
        ->instanceCommand(RejectPendingActionCommand::class, $pending->id)
        ->post()
        ->assertReturnsRefresh([$pending->id]);

    expect($pending->fresh()->status)->toBe('rejected');
});

it('requires authentication for the back-office', function () {
    auth()->logout();

    $this->get('/sharp/s-list/machine')->assertRedirect();
});

it('groups the menu in sections with resolvable Lucide icons', function () {
    $menu = (new SharpMenu)->build()->items();

    expect($menu)->toHaveCount(4)->each(fn ($item) => $item->toBeInstanceOf(SharpMenuItemSection::class));

    foreach (['server', 'scan-search', 'hand', 'clipboard-list'] as $icon) {
        expect(svg("lucide-{$icon}")->toHtml())->toContain('<svg');
    }

    $this->sharpList(MachineEntity::class)->get()->assertOk()->assertSee('Infrastructure')->assertSee('Traceability');
});

it('renders every list and show page without error', function () {
    $machine = Machine::factory()->create();
    $run = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'x', 'objective' => 'x']);
    $pending = PendingAction::create(['machine_id' => $machine->id, 'action' => 'clean_apt_cache', 'command' => 'x', 'risk' => 'low', 'reason' => 'x']);
    activity('ssh')->performedOn($machine)->event('ok')->withProperties(['command' => 'df'])->log('disk_usage');

    foreach ([MachineEntity::class, AgentRunEntity::class, PendingActionEntity::class, AuditEntryEntity::class] as $entity) {
        $this->sharpList($entity)->get()->assertOk();
    }

    $this->sharpShow(MachineEntity::class, $machine->id)->get()->assertOk();
    $this->sharpShow(AgentRunEntity::class, $run->id)->get()->assertOk();
    $this->sharpShow(PendingActionEntity::class, $pending->id)->get()->assertOk();
    $this->sharpForm(MachineEntity::class, $machine->id)->edit()->assertOk();
});
