<?php

use App\Ai\Agents\SysadminAgent;
use App\Ai\LiveReport;
use App\Ai\ScanRunner;
use App\Jobs\RunScan;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\User;
use App\Sharp\Entities\AgentRunEntity;
use App\Sharp\Entities\MachineEntity;
use App\Sharp\Machines\ScanMachineCommand;
use Code16\Sharp\Utils\Links\LinkToShowPage;
use Code16\Sharp\Utils\Testing\SharpAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, SharpAssertions::class);

function runWith(array $attributes = []): AgentRun
{
    return AgentRun::create($attributes + ['machine_id' => Machine::factory()->create()->id, 'provider' => 'x', 'objective' => 'o', 'status' => 'completed', 'report' => 'done']);
}

it('keeps guests and non-admins away from the scan report', function () {
    $run = runWith(['report' => 'secret findings']);

    $this->get(route('sentinel.scan', $run))->assertRedirect()->assertDontSee('secret findings');

    $this->actingAs(User::factory()->create());
    $this->get(route('sentinel.scan', $run))->assertRedirect()->assertDontSee('secret findings');
});

it('renders the report as safe markdown and can only be framed by the same origin', function () {
    $this->actingAs(User::factory()->admin()->create());
    $run = runWith(['report' => "## Findings\n\n- **root login** enabled\n\n<script>alert(1)</script>\n\n![x](https://evil.test/p.png)", 'summary' => '<b>sum</b>', 'severity' => 'high']);

    $this->get(route('sentinel.scan', $run))
        ->assertOk()
        ->assertSee('Findings')
        ->assertSeeHtml('<strong>root login</strong>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<img')
        ->assertDontSeeHtml('<b>sum</b>')
        ->assertSee('severity: high')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
});

it('polls while the scan is active and stops once it is finished', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('sentinel.scan', runWith(['status' => 'running', 'report' => 'partial', 'progress' => 'Running disk_usage…'])))
        ->assertSeeHtml('wire:poll.750ms')->assertSee('partial')->assertSee('Running disk_usage…');

    $this->get(route('sentinel.scan', runWith(['status' => 'queued', 'report' => null])))
        ->assertSeeHtml('wire:poll.750ms')->assertSee('Waiting for a worker');

    $this->get(route('sentinel.scan', runWith()))->assertDontSeeHtml('wire:poll');
});

it('embeds the live report in the scan show page instead of dumping raw text', function () {
    $this->actingAs(User::factory()->admin()->create());
    $run = runWith(['report' => '<script>alert(1)</script>']);

    $this->sharpShow(AgentRunEntity::class, $run->id)->get()->assertOk()
        ->assertInertia(fn ($page) => $page->where('show.data.report.text', fn ($html) => str_contains($html, route('sentinel.scan', $run, absolute: false)) && ! str_contains($html, 'alert(1)'))->etc());
});

it('sends the admin to the scan page after requesting a scan', function () {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();

    $this->sharpList(MachineEntity::class)
        ->instanceCommand(ScanMachineCommand::class, $machine->id)
        ->getForm()->post(['objective' => 'x'])
        ->assertReturnsLink(LinkToShowPage::make(AgentRunEntity::class, (string) AgentRun::firstOrFail()->id)->renderAsUrl());
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
