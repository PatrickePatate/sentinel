<?php

use App\Ai\Agents\SysadminAgent;
use App\Ai\LiveReport;
use App\Ai\ScanRunner;
use App\Jobs\RunFollowUp;
use App\Jobs\RunScan;
use App\Livewire\Machines\Show;
use App\Livewire\ScanReport;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function runWith(array $attributes = []): AgentRun
{
    return AgentRun::create($attributes + ['machine_id' => Machine::factory()->create()->id, 'provider' => 'x', 'objective' => 'o', 'status' => 'completed', 'report' => 'done']);
}

it('keeps guests and non-admins away from the scan report', function () {
    $run = runWith(['report' => 'secret findings']);

    $this->get(route('scans.show', $run))->assertRedirect(route('login'))->assertDontSee('secret findings');

    $this->actingAs(User::factory()->create());
    $this->get(route('scans.show', $run))->assertForbidden()->assertDontSee('secret findings');
});

it('renders the report as safe markdown', function () {
    $this->actingAs(User::factory()->admin()->create());
    $run = runWith(['report' => "## Findings\n\n- **root login** enabled\n\n<script>alert(1)</script>\n\n![x](https://evil.test/p.png)", 'summary' => '<b>sum</b>', 'severity' => 'high']);

    $this->get(route('scans.show', $run))
        ->assertOk()
        ->assertSee('Findings')
        ->assertSeeHtml('<strong>root login</strong>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<img')
        ->assertDontSeeHtml('<b>sum</b>')
        ->assertSee('High');
});

it('polls while the scan is active and stops once it is finished', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('scans.show', runWith(['status' => 'running', 'report' => 'partial', 'progress' => 'Running disk_usage…'])))
        ->assertSeeHtml('wire:poll.750ms')->assertSee('partial')->assertSee('Running disk_usage…');

    $this->get(route('scans.show', runWith(['status' => 'queued', 'report' => null])))
        ->assertSeeHtml('wire:poll.750ms')->assertSee('Waiting for a queue worker');

    $this->get(route('scans.show', runWith()))->assertDontSeeHtml('wire:poll.750ms');
});

it('sends the admin to the scan page after requesting a scan', function () {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a']);

    Livewire\Livewire::test(Show::class, ['machine' => $machine])
        ->set('objective', 'x')->call('scan')
        ->assertRedirect(route('scans.show', AgentRun::firstOrFail()));
});

it('stores the per-scan permission to act on low-risk issues', function () {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create(['host_key_fingerprint' => 'SHA256:a']);

    Livewire\Livewire::test(Show::class, ['machine' => $machine])->set('objective', 'x')->set('allowActions', true)->call('scan');

    expect(AgentRun::firstOrFail()->allow_actions)->toBeTrue();
});

it('does not copy the machine autonomy into the scan, so switching it off also stops queued scans', function () {
    $machine = Machine::factory()->create(['autonomy_enabled' => true]);

    expect(app(ScanRunner::class)->queue($machine, 'audit')->allow_actions)->toBeFalse()
        ->and(app(ScanRunner::class)->queue($machine, 'audit', allowActions: true)->allow_actions)->toBeTrue();
});

it('runs the queued scan into the same run and stores the report', function () {
    SysadminAgent::fake(['## All good']);
    $machine = Machine::factory()->create();
    $run = app(ScanRunner::class)->queue($machine, 'audit');

    (new RunScan($machine->id, 'audit', null, 'manual', $run->id))->handle(app(ScanRunner::class));

    expect(AgentRun::count())->toBe(1)
        ->and($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->report)->toContain('All good')
        ->and($run->fresh()->progress)->toBeNull();
});

it('never leaves a run looking active when its job fails', function () {
    $run = runWith(['status' => 'queued', 'report' => null]);

    (new RunScan($run->machine_id, 'o', null, 'manual', $run->id))->failed(new RuntimeException('worker killed'));

    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->report)->toContain('worker killed');
});

it('persists the streamed text and the current tool while a scan is running', function () {
    $run = runWith(['status' => 'running', 'report' => null]);
    $live = new LiveReport($run, interval: 0);
    $delta = fn (string $text) => new TextDelta('e', 'm', $text, time());

    $live->handle($delta('Checking '));
    $live->handle($delta('the disk'));
    expect($run->fresh()->report)->toBe('Checking the disk');
});

it('queues a follow-up on a finished scan and shows it in the thread', function () {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $scan = runWith(['report' => 'nginx is down']);

    Livewire\Livewire::test(ScanReport::class, ['run' => $scan])
        ->call('fixFindings')
        ->assertHasNoErrors()
        ->assertSee('Apply the remediation you recommended')
        ->assertSeeHtml('wire:poll.750ms')
        ->call('ask')
        ->assertHasErrors('message');

    $followUp = AgentRun::where('parent_run_id', $scan->id)->sole();
    expect($followUp->trigger)->toBe('follow_up')->and($followUp->status)->toBe('queued');
    Queue::assertPushed(RunFollowUp::class, fn ($job) => $job->runId === $followUp->id);
});

it('refuses a follow-up while the scan is still running', function () {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(ScanReport::class, ['run' => runWith(['status' => 'running'])])
        ->set('message', 'fix it')->call('ask')->assertHasErrors('message');

    Queue::assertNothingPushed();
});

it('runs a follow-up with the scan report as conversation', function () {
    SysadminAgent::fake(['Restart requested.']);
    $scan = runWith(['report' => 'nginx is down']);
    $followUp = app(ScanRunner::class)->queueFollowUp($scan, 'fix it');

    (new RunFollowUp($followUp->id))->handle(app(ScanRunner::class));

    expect($followUp->fresh()->status)->toBe('completed')->and($followUp->fresh()->report)->toBe('Restart requested.');
    SysadminAgent::assertPrompted(fn ($prompt) => $prompt->prompt === 'fix it'
        && collect($prompt->agent->messages())->contains(fn ($m) => $m->content === 'nginx is down'));
});

it('lists the actions of a scan and its follow-ups on the scan page, and lets the admin decide them', function () {
    $this->actingAs(User::factory()->admin()->create());
    $scan = runWith();
    $followUp = app(ScanRunner::class)->queueFollowUp($scan, 'fix it');
    $action = fn (?AgentRun $run, string $command) => PendingAction::create(['machine_id' => $scan->machine_id, 'agent_run_id' => $run?->id, 'action' => 'clean_apt_cache', 'command' => $command, 'risk' => 'low', 'reason' => 'r']);
    $mine = $action($scan, 'from-scan');
    $action($followUp, 'from-follow-up');
    $other = $action(runWith(), 'other-scan');

    Livewire\Livewire::test(ScanReport::class, ['run' => $scan])
        ->assertSee('from-scan')->assertSee('from-follow-up')->assertDontSee('other-scan')
        ->call('reject', $mine->id);

    expect($mine->fresh()->status)->toBe('rejected');

    // An action of another scan cannot be decided from this page.
    Livewire\Livewire::test(ScanReport::class, ['run' => $scan])->call('reject', $other->id)->assertNotFound();
});

it('shows the commands the agent ran for a scan as steps', function () {
    $this->actingAs(User::factory()->admin()->create());
    $scan = runWith();
    activity('ssh')->performedOn($scan->machine)->event('ok')->withProperties(['command' => 'df -hT', 'exit_code' => 0, 'agent_run_id' => $scan->id])->log('disk_usage');
    activity('ssh')->performedOn($scan->machine)->event('ok')->withProperties(['command' => 'free -m', 'agent_run_id' => 999])->log('memory_usage');

    Livewire\Livewire::test(ScanReport::class, ['run' => $scan])->assertSee('disk_usage')->assertSee('df -hT')->assertDontSee('memory_usage');
});
