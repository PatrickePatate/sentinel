<?php

use App\Livewire\Machines\Show;
use App\Models\Machine;
use App\Models\User;
use App\Ssh\Fake\FakeTransport;
use App\Ssh\HostKeyFingerprint;
use App\Ssh\Provisioning\ClientBundle;
use App\Ssh\Provisioning\ClientStatus;
use App\Ssh\Provisioning\ClientUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function machineWithToken(): array
{
    $machine = Machine::factory()->create(['host' => '10.1.2.3', 'private_key' => Machine::generatePrivateKey()]);

    return [$machine, $machine->issueProvisionToken()];
}

/** The fingerprints a machine would report: the one the fake server presents, and another host key. */
function reportedFingerprints(Machine $machine): string
{
    return implode("\n", [HostKeyFingerprint::of('ssh-rsa AAAAother'), FakeTransport::fingerprintFor($machine)]);
}

beforeEach(fn () => config(['sentinel.transport' => 'fake', 'sentinel.fake.latency_ms' => 0]));

it('serves the script to whoever holds the token, with the callback in it', function () {
    [$machine, $token] = machineWithToken();

    $response = $this->get("/provision/{$machine->id}/{$token}")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/x-shellscript')
        ->and($response->getContent())->toContain('main "$@"', "/provision/{$machine->id}/{$token}/callback", 'ssh-ed25519')
        ->and($response->getContent())->not->toContain('PRIVATE KEY');
});

it('refuses wrong, expired, replaced and revoked tokens, without telling which', function () {
    [$machine, $token] = machineWithToken();

    $this->get("/provision/{$machine->id}/wrong")->assertNotFound();
    $this->get('/provision/9999/'.$token)->assertNotFound();

    $machine->issueProvisionToken();
    $this->get("/provision/{$machine->id}/{$token}")->assertNotFound();

    $machine->forceFill(['provision_token_expires_at' => now()->subSecond()])->save();
    $this->get("/provision/{$machine->id}/{$machine->provision_token}")->assertNotFound();

    $machine->issueProvisionToken();
    $machine->forceFill(['revoked_at' => now()])->save();
    $this->get("/provision/{$machine->id}/{$machine->provision_token}")->assertNotFound();
});

it('pins the host key when the machine reports it, and burns the token', function () {
    [$machine, $token] = machineWithToken();

    $this->post("/provision/{$machine->id}/{$token}/callback", ['fingerprints' => reportedFingerprints($machine)])
        ->assertOk()->assertSee('Host key pinned');

    $machine->refresh();
    expect($machine->host_key_fingerprint)->toBe(FakeTransport::fingerprintFor($machine))
        ->and($machine->provisioned_at)->not->toBeNull()
        ->and($machine->provision_token)->toBeNull()
        ->and($machine->client_version)->not->toBeNull();

    $this->post("/provision/{$machine->id}/{$token}/callback", ['fingerprints' => reportedFingerprints($machine)])->assertNotFound();
});

it('does not pin a key the machine did not report', function () {
    [$machine, $token] = machineWithToken();

    $this->post("/provision/{$machine->id}/{$token}/callback", ['fingerprints' => HostKeyFingerprint::of('ssh-rsa AAAAother')])
        ->assertStatus(202)->assertSee('did not report');

    // The token is spent either way: a leaked link cannot be used to replace what the machine reported.
    expect($machine->fresh()->host_key_fingerprint)->toBeNull()->and($machine->fresh()->provision_token)->toBeNull();
    $this->post("/provision/{$machine->id}/{$token}/callback", ['fingerprints' => reportedFingerprints($machine)])->assertNotFound();
});

it('ignores malformed fingerprints', function () {
    [$machine, $token] = machineWithToken();

    $this->post("/provision/{$machine->id}/{$token}/callback", ['fingerprints' => "rm -rf /\nSHA256:short"])->assertStatus(422);
    expect($machine->fresh()->host_key_fingerprint)->toBeNull();
});

it('lets an admin issue the link from the machine page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create(['private_key' => Machine::generatePrivateKey()]);

    Livewire::test(Show::class, ['machine' => $machine, 'tab' => 'provisioning'])
        ->assertDontSee('curl -fsSL')
        ->call('issueLink')
        ->assertSee('curl -fsSL')->assertSee("/provision/{$machine->id}/".$machine->fresh()->provision_token)->assertSeeHtml('wire:poll.3s');
});

it('pins a fingerprint a human verified, and only the right one', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();

    Livewire::test(Show::class, ['machine' => $machine, 'tab' => 'provisioning'])->set('fingerprint', 'SHA256:wrong')->call('pin');
    expect($machine->fresh()->host_key_fingerprint)->toBeNull();

    Livewire::test(Show::class, ['machine' => $machine, 'tab' => 'provisioning'])->set('fingerprint', ' '.FakeTransport::fingerprintFor($machine))->call('pin');
    expect($machine->fresh()->host_key_fingerprint)->toBe(FakeTransport::fingerprintFor($machine));
});

it('updates the client over SSH when the bundle changed, and audits it', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create(['host_key_fingerprint' => FakeTransport::fingerprintFor(Machine::factory()->make(['host' => '10.9.9.9'])), 'host' => '10.9.9.9', 'private_key' => Machine::generatePrivateKey()]);
    $updater = app(ClientUpdater::class);

    expect($updater->check($machine)->state)->toBe(ClientStatus::UP_TO_DATE);

    config(['sentinel.actions.restartable_services' => ['nginx', 'my-new-service']]);
    expect($updater->check($machine)->state)->toBe(ClientStatus::OUTDATED);

    Livewire::test(Show::class, ['machine' => $machine])->call('updateClient');

    expect($updater->check($machine->fresh())->state)->toBe(ClientStatus::UP_TO_DATE)
        ->and(Activity::where('event', 'client_updated')->count())->toBe(1);
});

it('asks for the one-liner when the machine has no updater or an older one', function () {
    $machine = Machine::factory()->create(['host' => '10.9.9.8', 'client_version' => 'bundle=0123456789ab updater=000000000000']);

    expect(app(ClientUpdater::class)->lastKnown($machine)->state)->toBe(ClientStatus::UPDATER_OUTDATED)
        ->and(app(ClientUpdater::class)->lastKnown($machine)->canUpdateRemotely())->toBeFalse();

    expect(app(ClientUpdater::class)->lastKnown(Machine::factory()->make())->state)->toBe(ClientStatus::NOT_INSTALLED);
});

it('prints the one-liner from artisan', function () {
    $machine = Machine::factory()->create(['private_key' => Machine::generatePrivateKey()]);

    $this->artisan('sentinel:provision', ['machine' => $machine->id, '--one-liner' => true])
        ->expectsOutputToContain("curl -fsSL '".config('app.url')."/provision/{$machine->id}/")->assertSuccessful();
});

it('has a bundle version that is stable across calls', function () {
    expect(app(ClientBundle::class)->version('sentinel'))->toBe(app(ClientBundle::class)->version('sentinel'))->toMatch('/^[0-9a-f]{12}$/');
});
