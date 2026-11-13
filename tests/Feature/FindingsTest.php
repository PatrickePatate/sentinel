<?php

use App\Ai\Agents\SysadminAgent;
use App\Ai\ScanRunner;
use App\Ai\Severity;
use App\Ai\Tools\ReportFindingTool;
use App\Findings\FindingTracker;
use App\Livewire\Findings\Index;
use App\Models\AgentRun;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Notifier;
use App\Notifications\ScanReportNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function findingsRun(Machine $machine, array $reported, ?string $severity = 'medium', string $status = 'completed', string $profile = 'audit'): AgentRun
{
    return AgentRun::create([
        'machine_id' => $machine->id, 'provider' => 'x', 'objective' => 'audit', 'trigger' => 'scheduled', 'profile' => $profile,
        'status' => $status, 'severity' => $severity, 'summary' => 's', 'report' => 'r',
        'reported_findings' => collect($reported)->map(fn ($severity, $key) => ['title' => "Title {$key}", 'severity' => $severity, 'evidence' => 'e'])->all(),
    ]);
}

function reconciled(Machine $machine, array $reported, ?string $severity = 'medium', string $status = 'completed', string $profile = 'audit'): AgentRun
{
    $run = findingsRun($machine, $reported, $severity, $status, $profile);
    app(FindingTracker::class)->reconcile($run);

    return $run->fresh();
}

function finding(Machine $machine, string $key): Finding
{
    return Finding::where('machine_id', $machine->id)->where('key', $key)->sole();
}

it('records findings on the run and normalizes their key', function () {
    $run = findingsRun(Machine::factory()->create(), []);
    $tool = new ReportFindingTool($run);

    expect((string) $tool->handle(new Request(['key' => 'SSH Password Auth!', 'title' => ' Password  login ', 'severity' => 'high', 'evidence' => 'PasswordAuthentication yes'])))->toBe('RECORDED: ssh-password-auth')
        ->and($run->fresh()->reported_findings)->toBe(['ssh-password-auth' => ['title' => 'Password login', 'severity' => 'high', 'evidence' => 'PasswordAuthentication yes']]);
});

it('rejects bad findings and caps how many one scan can record', function () {
    $run = findingsRun(Machine::factory()->create(), []);
    $tool = new ReportFindingTool($run);

    expect((string) $tool->handle(new Request(['key' => '!!!', 'title' => 't', 'severity' => 'high'])))->toStartWith('ERROR')
        ->and((string) $tool->handle(new Request(['key' => 'k', 'title' => 't', 'severity' => 'none'])))->toStartWith('ERROR');

    foreach (range(1, ReportFindingTool::MAX_PER_RUN + 5) as $i) {
        $tool->handle(new Request(['key' => "k{$i}", 'title' => 't', 'severity' => 'low']));
    }

    expect($run->fresh()->reported_findings)->toHaveCount(ReportFindingTool::MAX_PER_RUN);
});

it('opens, keeps and resolves findings across scans', function () {
    $machine = Machine::factory()->create();

    $first = reconciled($machine, ['ssh-password' => 'high', 'disk-full' => 'medium']);
    $second = reconciled($machine, ['ssh-password' => 'high']);

    expect($first->findings_diff['new'])->toHaveCount(2)
        ->and($second->findings_diff)->toBe(['new' => [], 'escalated' => [], 'resolved' => [], 'ongoing' => [finding($machine, 'ssh-password')->id, finding($machine, 'disk-full')->id]])
        ->and(finding($machine, 'disk-full')->status)->toBe('open')
        ->and(finding($machine, 'disk-full')->misses)->toBe(1)
        ->and(finding($machine, 'ssh-password')->occurrences)->toBe(2)
        ->and(finding($machine, 'ssh-password')->first_seen_run_id)->toBe($first->id);

    $third = reconciled($machine, ['ssh-password' => 'high']);

    expect($third->findings_diff['resolved'])->toBe([finding($machine, 'disk-full')->id])
        ->and(finding($machine, 'disk-full')->status)->toBe('resolved')
        ->and(finding($machine, 'disk-full')->resolved_run_id)->toBe($third->id)
        ->and(finding($machine, 'disk-full')->misses)->toBe(0);
});

it('resolves a finding only after two scans in a row leave it out', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);

    $missed = reconciled($machine, ['ssh-password' => 'high']);
    expect($missed->findings_diff['resolved'])->toBe([])
        ->and(finding($machine, 'disk-full')->status)->toBe('open');

    // Reported again in between: the miss count starts over.
    reconciled($machine, ['disk-full' => 'medium']);
    expect(finding($machine, 'disk-full')->misses)->toBe(0);

    reconciled($machine, ['ssh-password' => 'high']);
    expect(finding($machine, 'disk-full')->status)->toBe('open')->and(finding($machine, 'disk-full')->misses)->toBe(1);

    $run = reconciled($machine, ['ssh-password' => 'high']);
    expect($run->findings_diff['resolved'])->toBe([finding($machine, 'disk-full')->id])
        ->and(finding($machine, 'disk-full')->status)->toBe('resolved');
});

it('treats a resolved finding that comes back after the quiet window as new again', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);
    reconciled($machine, [], 'none');
    finding($machine, 'disk-full')->update(['notified_severity' => 'medium', 'last_notified_at' => now()]);

    $this->travel(4)->days();
    $run = reconciled($machine, ['disk-full' => 'medium']);

    expect($run->findings_diff['new'])->toBe([finding($machine, 'disk-full')->id])
        ->and(finding($machine, 'disk-full')->status)->toBe('open')
        ->and(finding($machine, 'disk-full')->notified_severity)->toBeNull()
        ->and(finding($machine, 'disk-full')->last_notified_at)->toBeNull();
});

it('keeps a finding that comes back within the quiet window in the same episode', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);
    reconciled($machine, [], 'none');
    finding($machine, 'disk-full')->update(['notified_severity' => 'medium', 'last_notified_at' => now()]);

    $this->travel(2)->days();
    $run = reconciled($machine, ['disk-full' => 'medium']);

    expect($run->findings_diff['new'])->toBe([])
        ->and($run->findings_diff['ongoing'])->toBe([finding($machine, 'disk-full')->id])
        ->and(finding($machine, 'disk-full')->status)->toBe('open')
        ->and(finding($machine, 'disk-full')->notified_severity)->toBe('medium')
        ->and(finding($machine, 'disk-full')->last_notified_at)->not->toBeNull();
});

it('keeps an accepted finding accepted even when it gets worse', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'low']);
    finding($machine, 'disk-full')->update(['status' => 'accepted']);

    $run = reconciled($machine, ['disk-full' => 'high'], 'low');

    expect(finding($machine, 'disk-full')->status)->toBe('accepted')
        ->and(finding($machine, 'disk-full')->severity)->toBe('high')
        ->and($run->findings_diff['escalated'])->toBe([])
        ->and($run->findings_diff['ongoing'])->toBe([finding($machine, 'disk-full')->id]);
});

it('does not raise the verdict for acknowledged or accepted findings', function (string $status) {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'high']);
    finding($machine, 'disk-full')->update(['status' => $status]);

    expect(reconciled($machine, ['disk-full' => 'high'], 'low')->severity)->toBe('low');
})->with(['acknowledged', 'accepted']);

it('reopens an acknowledged or muted finding that got worse', function (string $status) {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'low']);
    finding($machine, 'disk-full')->update(['status' => $status, 'muted_until' => $status === 'muted' ? now()->addWeek() : null]);

    $run = reconciled($machine, ['disk-full' => 'high']);

    expect($run->findings_diff['escalated'])->toBe([finding($machine, 'disk-full')->id])
        ->and(finding($machine, 'disk-full')->status)->toBe('open')
        ->and(finding($machine, 'disk-full')->severity)->toBe('high');
})->with(['acknowledged', 'muted']);

it('keeps a muted finding muted until its date, then opens it again', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);
    finding($machine, 'disk-full')->update(['status' => 'muted', 'muted_until' => now()->addDay()]);

    reconciled($machine, ['disk-full' => 'medium']);
    expect(finding($machine, 'disk-full')->status)->toBe('muted');

    $this->travel(2)->days();
    reconciled($machine, ['disk-full' => 'medium']);
    expect(finding($machine, 'disk-full')->status)->toBe('open');
});

it('matches a renamed key to the finding it renames, keeping the stored key', function (string $renamed, string $known) {
    $machine = Machine::factory()->create();
    reconciled($machine, [$known => 'medium']);

    $run = reconciled($machine, [$renamed => 'medium']);

    expect(Finding::where('machine_id', $machine->id)->count())->toBe(1)
        ->and($run->findings_diff['new'])->toBe([])
        ->and($run->findings_diff['ongoing'])->toBe([finding($machine, $known)->id])
        ->and(finding($machine, $known)->occurrences)->toBe(2)
        ->and(finding($machine, $known)->title)->toBe("Title {$renamed}");
})->with([
    'host-swap-usage-rising' => ['host-swap-usage-rising', 'swap-usage-rising'],
    'docker-dns-resolver-unreachable' => ['docker-dns-resolver-unreachable', 'docker-dns-upstream-unreachable'],
    'api-new-hugoperez-bad-gateway' => ['api-new-hugoperez-bad-gateway', 'api-new-hugoperez-upstream-down'],
]);

it('does not match keys that only share a word', function (string $reported, string $known) {
    $machine = Machine::factory()->create();
    reconciled($machine, [$known => 'medium']);

    $run = reconciled($machine, [$reported => 'medium']);

    expect(Finding::where('machine_id', $machine->id)->count())->toBe(2)
        ->and($run->findings_diff['new'])->toBe([finding($machine, $reported)->id]);
})->with([
    ['ssh-root-login-permitted', 'ssh-password-auth-enabled'],
    ['hugoperez-http-503', 'api-new-hugoperez-bad-gateway'],
]);

it('lets a known finding be claimed by one renamed key only', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['swap-usage-rising' => 'medium']);

    $run = reconciled($machine, ['host-swap-usage-rising' => 'medium', 'swap-usage-rising-peak' => 'medium']);

    expect(Finding::where('machine_id', $machine->id)->count())->toBe(2)
        ->and($run->findings_diff['new'])->toHaveCount(1)
        ->and($run->findings_diff['ongoing'])->toHaveCount(1);
});

it('matches a renamed key only to a finding resolved within the same-key window', function (int $days, int $findings) {
    $machine = Machine::factory()->create();
    reconciled($machine, ['swap-usage-rising' => 'medium']);
    reconciled($machine, [], 'none');

    $this->travel($days)->days();
    reconciled($machine, ['host-swap-usage-rising' => 'medium']);

    expect(Finding::where('machine_id', $machine->id)->count())->toBe($findings);
})->with([
    'recently resolved' => [5, 1],
    'resolved long ago' => [15, 2],
]);

it('resolves nothing from a failed scan, a scan without verdict, or a scan of another profile', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);

    reconciled($machine, [], 'medium', 'failed');
    reconciled($machine, [], null);
    reconciled($machine, [], 'none', profile: 'webserver');

    expect(finding($machine, 'disk-full')->status)->toBe('open');
});

it('does not resolve known findings when the agent gave a verdict without listing any', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);

    $run = reconciled($machine, [], 'medium');

    expect(finding($machine, 'disk-full')->status)->toBe('open')
        ->and($run->findings_diff['resolved'])->toBe([]);
});

it('resolves everything when a scan finds nothing at all', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);

    reconciled($machine, [], 'none');

    expect(finding($machine, 'disk-full')->status)->toBe('resolved');
});

it('keeps the verdict at least as severe as the worst finding of the scan, muted ones aside', function () {
    $machine = Machine::factory()->create();

    expect(reconciled($machine, ['ssh-password' => 'high'], 'none')->severity)->toBe('high');

    finding($machine, 'ssh-password')->update(['status' => 'muted', 'muted_until' => now()->addWeek()]);

    expect(reconciled($machine, ['ssh-password' => 'high'], 'low')->severity)->toBe('low');
});

it('reconciles findings when a scan completes', function () {
    $machine = Machine::factory()->create();
    SysadminAgent::fake(['All checked.']);
    $run = app(ScanRunner::class)->queue($machine, 'audit');
    $run->update(['reported_findings' => ['disk-full' => ['title' => 'Disk almost full', 'severity' => 'medium', 'evidence' => '95%']], 'severity' => 'low']);

    $run = app(ScanRunner::class)->run($machine, 'audit', run: $run);

    expect($run->status)->toBe('completed')
        ->and($run->severity)->toBe('medium')
        ->and($run->findings_diff['new'])->toHaveCount(1);
});

it('gives the agent the open findings of the machine, for this profile only', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);
    reconciled($machine, ['nginx-down' => 'high'], profile: 'webserver');
    $run = findingsRun($machine, [], null, 'running');

    $instructions = (string) (new SysadminAgent($machine, $run, requiresVerdict: true))->instructions();

    // The other check's finding is only named as something not to report again.
    expect($instructions)->toContain('disk-full (medium)')
        ->and(Str::before($instructions, 'Already tracked by the other check'))->not->toContain('nginx-down')
        ->and(Str::after($instructions, 'Already tracked by the other check'))->toContain('nginx-down');
});

it('does not notify a scan that only repeats known findings', function () {
    Notification::fake();
    NotificationChannel::create(['name' => 'ops', 'type' => 'mail', 'settings' => ['email' => 'ops@example.com'], 'min_severity' => 'low']);
    $machine = Machine::factory()->create();

    app(Notifier::class)->scanFinished(reconciled($machine, ['disk-full' => 'medium']));
    app(Notifier::class)->scanFinished(reconciled($machine, ['disk-full' => 'medium']));
    app(Notifier::class)->scanFinished(reconciled($machine, ['disk-full' => 'medium', 'ssh-password' => 'medium']));
    app(Notifier::class)->scanFinished(reconciled($machine, ['disk-full' => 'medium', 'ssh-password' => 'medium']));
    app(Notifier::class)->scanFinished(reconciled($machine, ['disk-full' => 'high', 'ssh-password' => 'medium']));

    Notification::assertSentTimes(ScanReportNotification::class, 3);
});

it('always notifies high verdicts, even when nothing changed', function () {
    Notification::fake();
    NotificationChannel::create(['name' => 'ops', 'type' => 'mail', 'settings' => ['email' => 'ops@example.com'], 'min_severity' => 'low']);
    $machine = Machine::factory()->create();

    reconciled($machine, ['ssh-password' => 'high']);
    app(Notifier::class)->scanFinished(reconciled($machine, ['ssh-password' => 'high']));

    Notification::assertSentTimes(ScanReportNotification::class, 1);
});

it('lists what changed in the notification', function () {
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);
    reconciled($machine, ['other-issue' => 'low']);
    $run = reconciled($machine, ['ssh-password' => 'high']);
    $channel = NotificationChannel::create(['name' => 'ops', 'type' => 'mail', 'settings' => ['email' => 'ops@example.com'], 'min_severity' => 'low']);

    $lines = (new ScanReportNotification($run->load('machine'), Severity::High))->toMail($channel)->introLines;

    expect($lines)->toContain('New: Title ssh-password (high)')->toContain('Resolved: Title disk-full (medium)');
});

it('lets an administrator acknowledge, mute, fix and reopen an issue, with an audit entry each time', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);
    $id = finding($machine, 'disk-full')->id;

    $page = Livewire::test(Index::class)->assertSee('Title disk-full');

    $page->call('acknowledge', $id);
    expect(finding($machine, 'disk-full')->status)->toBe('acknowledged');

    $page->call('mute', $id, 30);
    expect(finding($machine, 'disk-full')->muted_until->isFuture())->toBeTrue();

    $page->call('resolve', $id);
    expect(finding($machine, 'disk-full')->status)->toBe('resolved');

    $page->call('reopen', $id);
    expect(finding($machine, 'disk-full')->status)->toBe('open')
        ->and(Activity::where('event', 'like', 'finding_%')->count())->toBe(4);

    Livewire::test(Index::class)->call('mute', $id, 3)->assertStatus(422);
});

it('filters issues and hides resolved ones by default', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium', 'ssh-password' => 'high']);
    reconciled($machine, ['ssh-password' => 'high']);
    reconciled($machine, ['ssh-password' => 'high']);

    Livewire::test(Index::class)->assertSee('Title ssh-password')->assertDontSee('Title disk-full')
        ->set('status', 'resolved')->assertSee('Title disk-full')->assertDontSee('Title ssh-password')
        ->set('status', 'all')->set('severity', 'high')->assertSee('Title ssh-password')->assertDontSee('Title disk-full');
});

it('keeps the issues page for administrators', function () {
    $this->actingAs(User::factory()->create())->get(route('findings.index'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('findings.index'))->assertOk();
});

it('shows what a scan changed on its page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();
    reconciled($machine, ['disk-full' => 'medium']);
    reconciled($machine, ['other-issue' => 'low']);
    $run = reconciled($machine, ['ssh-password' => 'high']);

    $this->get(route('scans.show', $run))->assertOk()->assertSee('Changes since the last scan')->assertSee('Title ssh-password')->assertSee('Resolved');
});
