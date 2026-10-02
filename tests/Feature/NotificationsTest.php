<?php

use App\Ai\Agents\SysadminAgent;
use App\Ai\ScanRunner;
use App\Ai\Severity;
use App\Ai\Tools\SubmitVerdictTool;
use App\Jobs\RunScan;
use App\Livewire\Channels\Index;
use App\Livewire\Machines\Form;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Models\User;
use App\Notifications\ActionApprovalNotification;
use App\Notifications\Channels\TelegramClient;
use App\Notifications\Notifier;
use App\Notifications\ScanReportNotification;
use App\Notifications\TestNotification;
use App\Ssh\ActionCatalog;
use App\Ssh\CommandResult;
use App\Ssh\SshTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function mailChannel(string $min = 'medium', array $extra = []): NotificationChannel
{
    return NotificationChannel::create($extra + ['name' => 'ops mail', 'type' => 'mail', 'settings' => ['email' => 'ops@example.com'], 'min_severity' => $min]);
}

function telegramChannel(array $settings = [], array $extra = []): NotificationChannel
{
    return NotificationChannel::create($extra + [
        'name' => 'ops telegram', 'type' => 'telegram', 'min_severity' => 'medium',
        'settings' => $settings + ['bot_token' => '123:ABC', 'chat_id' => '4242', 'approver_ids' => '4242', 'webhook_secret' => 'topsecret'],
    ]);
}

function finishedRun(?string $severity, string $status = 'completed'): AgentRun
{
    return AgentRun::create(['machine_id' => Machine::factory()->create()->id, 'provider' => 'x', 'objective' => 'o', 'status' => $status, 'severity' => $severity, 'summary' => 's', 'report' => 'r', 'trigger' => 'scheduled']);
}

function pendingFor(Machine $machine): PendingAction
{
    return PendingAction::create([
        'machine_id' => $machine->id, 'action' => 'clean_apt_cache', 'arguments' => [],
        'command' => ActionCatalog::default()->get('clean_apt_cache')->command([]), 'risk' => 'low', 'reason' => 'test',
    ]);
}

function recordingSsh(): object
{
    $transport = new class implements SshTransport
    {
        public array $commands = [];

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult('cache cleaned', 0);
        }
    };
    app()->instance(SshTransport::class, $transport);

    return $transport;
}

function pressButton(NotificationChannel $channel, string $data, string $userId = '4242', string $secret = 'topsecret')
{
    return test()->postJson(route('sentinel.telegram.webhook', $channel), [
        'callback_query' => ['id' => 'cb1', 'data' => $data, 'from' => ['id' => (int) $userId, 'username' => 'lucien'], 'message' => ['message_id' => 9, 'chat' => ['id' => 4242]]],
    ], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
}

it('notifies each channel according to its minimum severity, and treats a missing verdict as medium', function () {
    Notification::fake();
    $mail = mailChannel('medium');
    $telegram = telegramChannel(extra: ['min_severity' => 'high']);
    $off = mailChannel('none', ['name' => 'disabled', 'enabled' => false]);

    app(Notifier::class)->scanFinished(finishedRun('low'));
    Notification::assertNothingSent();

    app(Notifier::class)->scanFinished(finishedRun('medium'));
    Notification::assertSentTo($mail, ScanReportNotification::class);
    Notification::assertNotSentTo($telegram, ScanReportNotification::class);

    app(Notifier::class)->scanFinished(finishedRun('critical'));
    Notification::assertSentTo($telegram, ScanReportNotification::class);

    app(Notifier::class)->scanFinished(finishedRun(null)); // no verdict: fail towards telling someone
    Notification::assertSentToTimes($mail, ScanReportNotification::class, 3);
    Notification::assertNotSentTo($off, ScanReportNotification::class);
});

it('treats a failed scan as high severity', function () {
    Notification::fake();
    $telegram = telegramChannel(extra: ['min_severity' => 'high']);

    app(Notifier::class)->scanFinished(finishedRun(null, 'failed'));

    Notification::assertSentTo($telegram, ScanReportNotification::class);
});

it('does not tell about scans on channels that only want approvals', function () {
    Notification::fake();
    $channel = mailChannel('none', ['notify_scans' => false]);

    app(Notifier::class)->scanFinished(finishedRun('critical'));

    Notification::assertNotSentTo($channel, ScanReportNotification::class);
});

it('sends approval requests with approve and reject buttons and escapes everything', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $channel = telegramChannel();
    $pending = pendingFor(Machine::factory()->create(['name' => '<b>evil</b>']));
    $pending->update(['reason' => '<script>x</script>']);

    $channel->notifyNow(new ActionApprovalNotification($pending->fresh('machine')));

    Http::assertSent(function ($request) use ($pending) {
        $buttons = $request['reply_markup']['inline_keyboard'][0];

        return str_contains($request->url(), 'bot123:ABC/sendMessage')
            && $request['chat_id'] === '4242'
            && $buttons[0]['callback_data'] === "approve:{$pending->id}" && $buttons[1]['callback_data'] === "reject:{$pending->id}"
            && str_contains($request['text'], '&lt;b&gt;evil&lt;/b&gt;') && str_contains($request['text'], '&lt;script&gt;')
            && ! str_contains($request['text'], '<script>');
    });
});

it('sends an approval mail and notifies when the executor holds an action', function () {
    Notification::fake();
    $channel = mailChannel();
    $pending = pendingFor(Machine::factory()->create());

    app(Notifier::class)->approvalNeeded($pending);

    Notification::assertSentTo($channel, ActionApprovalNotification::class);
});

it('reports a Telegram error without leaking the bot token', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

    try {
        telegramChannel()->notifyNow(new TestNotification);
        $this->fail('should have thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Unauthorized')->not->toContain('123:ABC');
    }
});

it('runs the approve button: right secret, allowed user, executes once', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $transport = recordingSsh();
    $channel = telegramChannel();
    $pending = pendingFor(Machine::factory()->create());

    pressButton($channel, "approve:{$pending->id}")->assertNoContent();
    pressButton($channel, "approve:{$pending->id}")->assertNoContent(); // replay / double tap

    expect($transport->commands)->toHaveCount(1)
        ->and($pending->fresh()->status)->toBe('executed');

    $audit = Activity::where('event', 'action_approved')->first();
    expect($audit->properties['via'])->toBe('telegram')->and($audit->properties['telegram_user_id'])->toBe('4242');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText') && ! isset($r['reply_markup']) && str_contains($r['text'], 'Approved and run'));
});

it('runs the reject button without executing anything', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $transport = recordingSsh();
    $channel = telegramChannel();
    $pending = pendingFor(Machine::factory()->create());

    pressButton($channel, "reject:{$pending->id}")->assertNoContent();

    expect($transport->commands)->toBeEmpty()->and($pending->fresh()->status)->toBe('rejected');
});

it('refuses button presses from anyone not allowed, and audits the attempt', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $transport = recordingSsh();
    $channel = telegramChannel(['approver_ids' => '4242']);
    $pending = pendingFor(Machine::factory()->create());

    pressButton($channel, "approve:{$pending->id}", userId: '666')->assertNoContent();

    expect($transport->commands)->toBeEmpty()->and($pending->fresh()->status)->toBe('pending')
        ->and(Activity::where('event', 'action_approval_denied')->count())->toBe(1);
});

it('refuses the webhook without the secret header, for a disabled channel or an unknown callback', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $transport = recordingSsh();
    $channel = telegramChannel();
    $pending = pendingFor(Machine::factory()->create());

    pressButton($channel, "approve:{$pending->id}", secret: 'wrong')->assertNotFound();
    $this->postJson(route('sentinel.telegram.webhook', $channel), ['callback_query' => ['id' => 'x', 'data' => "approve:{$pending->id}"]])->assertNotFound();

    $channel->update(['enabled' => false]);
    pressButton($channel, "approve:{$pending->id}")->assertNotFound();

    $channel->update(['enabled' => true]);
    pressButton($channel, 'rm -rf /')->assertNoContent();

    expect($transport->commands)->toBeEmpty()->and($pending->fresh()->status)->toBe('pending');
});

it('does not run an expired or stale approval from Telegram', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    $transport = recordingSsh();
    $channel = telegramChannel();
    $pending = pendingFor(Machine::factory()->create());
    $pending->forceFill(['created_at' => now()->subHours(48)])->save();

    pressButton($channel, "approve:{$pending->id}")->assertNoContent();

    expect($transport->commands)->toBeEmpty()->and($pending->fresh()->status)->toBe('expired');
});

it('queues autonomous scans only for machines that are due, once', function () {
    Queue::fake();
    $due = Machine::factory()->create(['scan_interval_minutes' => 60, 'host_key_fingerprint' => 'SHA256:a']);
    Machine::factory()->create(['scan_interval_minutes' => 60, 'host_key_fingerprint' => 'SHA256:b', 'last_scan_at' => now()->subMinutes(10)]);
    Machine::factory()->create(['scan_interval_minutes' => null, 'host_key_fingerprint' => 'SHA256:c']);
    Machine::factory()->create(['scan_interval_minutes' => 60, 'host_key_fingerprint' => null]);
    Machine::factory()->create(['scan_interval_minutes' => 60, 'host_key_fingerprint' => 'SHA256:d', 'revoked_at' => now()]);

    $this->artisan('sentinel:scan-due')->assertSuccessful();
    $this->artisan('sentinel:scan-due')->assertSuccessful();

    Queue::assertPushed(RunScan::class, 1);
    Queue::assertPushed(RunScan::class, fn (RunScan $job) => $job->machineId === $due->id && $job->trigger === 'scheduled' && $job->userId === null);
    expect($due->fresh()->last_scan_at)->not->toBeNull();

    $this->travel(61)->minutes();
    $this->artisan('sentinel:scan-due')->assertSuccessful();
    Queue::assertPushed(RunScan::class, 3); // the first machine again, and the one that was 10 minutes in
});

it('records the verdict the agent submits and rejects an unknown severity', function () {
    $run = finishedRun(null);
    $tool = new SubmitVerdictTool($run);

    expect($tool->handle(new Request(['severity' => 'nonsense', 'summary' => 'x'])))->toStartWith('ERROR');

    $tool->handle(new Request(['severity' => 'high', 'summary' => 'Root login from unknown IP']));

    expect($run->fresh()->severity)->toBe(Severity::High->value)->and($run->fresh()->summary)->toBe('Root login from unknown IP');
});

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('sets the scan frequency per machine from the dashboard and clears it', function () {
    $fill = fn ($component, int $minutes) => $component->set('name', 'web')->set('host', '10.0.0.9')->set('scan_interval_minutes', $minutes)->call('save');

    $fill(Livewire::test(Form::class), 360)->assertHasNoErrors();
    $machine = Machine::firstWhere('name', 'web');
    expect($machine->scan_interval_minutes)->toBe(360);

    $fill(Livewire::test(Form::class, ['machine' => $machine]), 0)->assertHasNoErrors();
    expect($machine->fresh()->scan_interval_minutes)->toBeNull();

    $fill(Livewire::test(Form::class, ['machine' => $machine]), 5)->assertHasErrors('scan_interval_minutes');
});

it('creates a Telegram channel keeping the bot token secret and the stored settings encrypted', function () {
    $form = fn (array $data, ?NotificationChannel $channel = null) => Livewire::test(App\Livewire\Channels\Form::class, $channel ? ['channel' => $channel] : [])
        ->set($data + ['name' => 'bot', 'type' => 'telegram', 'chat_id' => '4242', 'min_severity' => 'high'])->call('save')->assertHasNoErrors();

    $form(['bot_token' => '123:ABC', 'approver_ids' => '4242']);

    $channel = NotificationChannel::firstWhere('name', 'bot');
    expect($channel->setting('bot_token'))->toBe('123:ABC')
        ->and(DB::table('notification_channels')->value('settings'))->not->toContain('123:ABC');

    // Editing never shows the token back, and leaving it empty keeps it.
    Livewire::test(App\Livewire\Channels\Form::class, ['channel' => $channel])->assertSet('bot_token', '')->assertDontSee('123:ABC');
    $form(['bot_token' => '', 'approver_ids' => '4242, 7'], $channel);

    expect($channel->fresh()->setting('bot_token'))->toBe('123:ABC')->and($channel->fresh()->approverIds())->toBe(['4242', '7']);

    Livewire::test(Index::class)->assertSee('bot')->assertDontSee('123:ABC');
});

it('uses the scheduled-scan model only for scheduled scans, falling back to the default', function () {
    SysadminAgent::fake(['ok', 'ok', 'ok']);
    config(['sentinel.agent.provider' => 'openai', 'sentinel.agent.model' => 'strong-model', 'sentinel.agent.scheduled_provider' => 'openai', 'sentinel.agent.scheduled_model' => 'cheap-model']);
    $machine = Machine::factory()->create();
    $runner = app(ScanRunner::class);

    $runner->run($machine, 'o', trigger: 'scheduled');
    SysadminAgent::assertPrompted(fn ($prompt) => $prompt->model === 'cheap-model');

    $runner->run($machine, 'o');
    SysadminAgent::assertPrompted(fn ($prompt) => $prompt->model === 'strong-model');

    // A scheduled model name without its provider must never be used on the default provider.
    config(['sentinel.agent.scheduled_provider' => null, 'sentinel.agent.scheduled_model' => 'orphan-model']);
    $runner->run($machine, 'o', trigger: 'scheduled');

    $seen = [];
    try {
        SysadminAgent::assertPrompted(function ($prompt) use (&$seen) {
            $seen[] = $prompt->model;

            return false;
        });
    } catch (Throwable) {
    }

    expect($seen)->toBe(['cheap-model', 'strong-model', 'strong-model']);
});

it('treats empty model env values as unset so the provider default is used', function () {
    putenv('SENTINEL_GATE_MODEL=');
    $_ENV['SENTINEL_GATE_MODEL'] = '';
    $_SERVER['SENTINEL_GATE_MODEL'] = '';

    $config = require config_path('sentinel.php');

    expect($config['gate']['model'])->toBeNull();

    putenv('SENTINEL_GATE_MODEL');
    unset($_ENV['SENTINEL_GATE_MODEL'], $_SERVER['SENTINEL_GATE_MODEL']);
});

it('detects the telegram chat id from the message sent to the bot', function () {
    $form = Livewire::test(App\Livewire\Channels\Form::class);
    $code = $form->get('start_code');
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [
        ['update_id' => 1, 'message' => ['chat' => ['id' => 4242, 'first_name' => 'Ada'], 'from' => ['id' => 4242], 'text' => "/start {$code}"]],
        ['update_id' => 2, 'message' => ['chat' => ['id' => 666], 'from' => ['id' => 666], 'text' => '/start wrongcode']],
    ]])]);

    $form->call('detectChat')->assertHasErrors('bot_token')
        ->set('bot_token', '123:ABC')->call('detectChat')
        ->assertHasNoErrors()->assertSet('chat_id', '4242')->assertSet('approver_ids', '4242');
});

it('explains when nothing was sent to the telegram bot yet', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    Livewire::test(App\Livewire\Channels\Form::class)->set('bot_token', '123:ABC')->call('detectChat')->assertHasErrors('chat_id');
});

it('accepts the boolean result Telegram returns when registering the webhook', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

    TelegramClient::withToken('123:ABC')->setWebhook('https://example.test/hook', 'secret');

    Http::assertSentCount(1);
});
