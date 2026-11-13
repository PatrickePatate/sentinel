<?php

use App\Ai\Agents\SysadminAgent;
use App\Ai\Tools\ReportFindingTool;
use App\Livewire\Findings\Index;
use App\Models\AgentRun;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** A finding of the machine as a scan of the given profile left it. */
function promptFinding(Machine $machine, string $profile, string $key, array $attributes = []): Finding
{
    return Finding::create($attributes + [
        'machine_id' => $machine->id, 'profile' => $profile, 'key' => $key, 'title' => "Title {$key}",
        'severity' => 'medium', 'status' => 'open', 'evidence' => 'e', 'occurrences' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

/** The instructions the agent gets for a scan of the given profile. */
function promptInstructions(Machine $machine, string $profile): string
{
    $run = AgentRun::create(['machine_id' => $machine->id, 'provider' => 'x', 'objective' => 'x', 'status' => 'running', 'profile' => $profile]);

    return (string) (new SysadminAgent($machine, $run, requiresVerdict: true))->instructions();
}

it('lists the findings this profile fixed in the last 14 days, so a comeback keeps its key', function () {
    $machine = Machine::factory()->create();
    promptFinding($machine, 'audit', 'ssh-root-login', ['status' => 'resolved', 'severity' => 'high', 'resolved_at' => now()->subDays(3)]);
    promptFinding($machine, 'audit', 'old-swap-use', ['status' => 'resolved', 'resolved_at' => now()->subDays(20)]);
    promptFinding($machine, 'webserver', 'nginx-down', ['status' => 'resolved', 'resolved_at' => now()->subDay()]);

    $prompt = promptInstructions($machine, 'audit');

    expect($prompt)->toContain('Recently fixed in this profile (last 14 days): if one is back, reuse its exact key:')
        ->toContain('- ssh-root-login (high): Title ssh-root-login')
        ->not->toContain('old-swap-use')
        ->not->toContain('nginx-down');
});

it('lists the open findings of the other profile and says not to report them again', function () {
    $machine = Machine::factory()->create();
    promptFinding($machine, 'audit', 'ssh-password-auth', ['severity' => 'high']);
    promptFinding($machine, 'audit', 'backup-missing', ['status' => 'resolved', 'resolved_at' => now()]);
    promptFinding($machine, 'webserver', 'php-fpm-down', ['severity' => 'critical']);

    $prompt = promptInstructions($machine, 'webserver');

    expect($prompt)->toContain('Already tracked by the other check of this machine: do not report these again:')
        ->toContain('- ssh-password-auth (high): Title ssh-password-auth')
        ->not->toContain('backup-missing')
        ->toContain('- php-fpm-down (critical): Title php-fpm-down');
});

it('keeps each known list to its limit', function () {
    $machine = Machine::factory()->create();
    foreach (range(1, 55) as $i) {
        promptFinding($machine, 'audit', sprintf('open-%02d', $i));
    }
    foreach (range(1, 35) as $i) {
        promptFinding($machine, 'webserver', sprintf('other-%02d', $i));
    }

    preg_match_all('/^- open-\d+ /m', promptInstructions($machine, 'audit'), $open);
    preg_match_all('/^- other-\d+ /m', promptInstructions($machine, 'audit'), $other);

    expect($open[0])->toHaveCount(50)
        ->and($other[0])->toHaveCount(30);
});

it('tells the agent to reuse keys, to re-report what it could not check, and to keep severities', function () {
    $prompt = promptInstructions(Machine::factory()->create(), 'audit');

    expect($prompt)->toContain('Reuse a listed key rather than inventing a synonym')
        ->toContain('report it again under its exact key with its previous severity')
        ->toContain('never file a separate finding whose subject is that something is "unverified" or "could not be revalidated"')
        ->toContain('Do not change a known finding\'s severity unless the evidence changed');
});

it('tells the agent that the web server check covers the web stack only', function () {
    $machine = Machine::factory()->create();

    expect(promptInstructions($machine, 'webserver'))
        ->toContain('Findings must be about the web stack')
        ->toContain('general hardening (SSH settings, firewall, package updates, backups) belongs to the security audit and is not reported here')
        ->and(promptInstructions($machine, 'audit'))->not->toContain('belongs to the security audit');
});

it('describes the same rules to the model in the report_finding tool', function () {
    $tool = new ReportFindingTool(AgentRun::create(['machine_id' => Machine::factory()->create()->id, 'provider' => 'x', 'objective' => 'x']));

    expect((string) $tool->description())->toContain('report it again with its previous severity')
        ->and($tool->schema(new JsonSchemaTypeFactory))->toHaveKeys(['key', 'title', 'severity', 'evidence']);
});

it('lets an administrator accept a risk: it stays listed, is no longer open, and is audited', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();
    $id = promptFinding($machine, 'audit', 'disk-full')->id;

    Livewire::test(Index::class)->assertSee('Title disk-full')->call('accept', $id)->assertSee('Title disk-full');

    $finding = Finding::findOrFail($id);
    expect($finding->status)->toBe('accepted')
        ->and($finding->needsAttention())->toBeFalse()
        ->and(Activity::where('event', 'finding_accepted')->count())->toBe(1);

    Livewire::test(Index::class)->set('status', 'accepted')->assertSee('Title disk-full');
    Livewire::test(Index::class)->set('status', 'open')->assertDontSee('Title disk-full');
});
