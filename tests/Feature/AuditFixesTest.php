<?php

use App\Ai\Agents\SysadminAgent;
use App\Jobs\RunScan;
use App\Livewire\MachineChat;
use App\Models\ChatMessage;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Models\User;
use App\Sharp\Entities\MachineEntity;
use App\Sharp\Machines\ScanMachineCommand;
use App\Ssh\ActionCatalog;
use App\Ssh\ActionExecutor;
use App\Ssh\AuditTrail;
use App\Ssh\CommandResult;
use App\Ssh\Gate\RiskGate;
use App\Ssh\SshTransport;
use Code16\Sharp\Utils\Testing\SharpAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, SharpAssertions::class);

function pendingCleanCache(?Machine $machine = null): PendingAction
{
    return PendingAction::create([
        'machine_id' => ($machine ?? Machine::factory()->create())->id, 'action' => 'clean_apt_cache', 'arguments' => [],
        'command' => 'apt-get clean 2>&1', 'risk' => 'low', 'reason' => 'test',
    ]);
}

function countingTransport(): object
{
    return new class implements SshTransport
    {
        public int $runs = 0;

        public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
        {
            $this->runs++;

            return new CommandResult('ok', 0);
        }
    };
}

function executor(object $transport): ActionExecutor
{
    return new ActionExecutor(ActionCatalog::default(), $transport, new RiskGate, new AuditTrail);
}

it('escapes everything it streams to the browser (wire:stream inserts raw HTML)', function () {
    SysadminAgent::fake(['<img src=x onerror=alert(1)>']);
    $this->actingAs(User::factory()->admin()->create());

    $streamed = '';
    ob_start(function (string $chunk) use (&$streamed) {
        $streamed .= $chunk;

        return '';
    });
    Livewire::test(MachineChat::class, ['machine' => Machine::factory()->create()])
        ->set('message', '<script>steal()</script>')
        ->call('send');
    ob_end_clean();

    expect($streamed)->not->toContain('<img')->not->toContain('<script>')
        ->and($streamed)->toContain('&lt;img')->toContain('&lt;script&gt;');
});

it('runs an approved action only once, even when approved twice', function () {
    $transport = countingTransport();
    $pending = pendingCleanCache();

    executor($transport)->approve($pending);

    expect(fn () => executor($transport)->approve(PendingAction::find($pending->id)))->toThrow(HttpException::class)
        ->and(fn () => executor($transport)->reject(PendingAction::find($pending->id)))->toThrow(HttpException::class)
        ->and($transport->runs)->toBe(1);
});

it('refuses to run a command that differs from the one that was reviewed', function () {
    config(['sentinel.actions.use_sudo' => true]);
    $transport = countingTransport();
    $pending = pendingCleanCache();

    $result = executor($transport)->approve($pending);

    expect($result)->toStartWith('ERROR')->and($transport->runs)->toBe(0)
        ->and($pending->fresh()->status)->toBe('stale');
});

it('expires pending actions', function () {
    $transport = countingTransport();
    $pending = pendingCleanCache();
    $pending->forceFill(['created_at' => now()->subHours(25)])->save();

    expect(fn () => executor($transport)->approve($pending))->toThrow(HttpException::class)
        ->and($pending->fresh()->status)->toBe('expired')
        ->and($transport->runs)->toBe(0);
});

it('records who approved and who rejected', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $transport = countingTransport();

    executor($transport)->approve(pendingCleanCache());
    executor($transport)->reject(pendingCleanCache());

    expect(Activity::whereIn('event', ['action_approved', 'action_rejected'])->pluck('causer_id')->unique()->all())->toBe([$admin->id]);
});

it('rate limits chat messages', function () {
    SysadminAgent::fake(['ok']);
    config(['sentinel.limits.chat_messages_per_minute' => 1]);
    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    RateLimiter::clear('chat:'.$user->id);

    $chat = Livewire::test(MachineChat::class, ['machine' => Machine::factory()->create()]);
    $chat->set('message', 'one')->call('send')->assertHasNoErrors();
    $chat->set('message', 'two')->call('send')->assertHasErrors('message');
});

it('only replays the last messages of a conversation to the model', function () {
    SysadminAgent::fake(['ok']);
    config(['sentinel.limits.chat_history_messages' => 2]);
    $user = User::factory()->admin()->create();
    $this->actingAs($user);
    $machine = Machine::factory()->create();

    foreach (range(1, 6) as $i) {
        ChatMessage::create(['machine_id' => $machine->id, 'user_id' => $user->id, 'role' => $i % 2 ? 'user' : 'assistant', 'content' => "old {$i}"]);
    }

    Livewire::test(MachineChat::class, ['machine' => $machine])->set('message', 'new')->call('send');

    SysadminAgent::assertPrompted(fn ($prompt) => $prompt->prompt === 'new' && count($prompt->agent->messages()) === 2);
});

it('stores the author of a queued scan', function () {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();

    $this->sharpList(MachineEntity::class)
        ->instanceCommand(ScanMachineCommand::class, $machine->id)
        ->getForm()->post(['objective' => 'x'])->assertReturnsInfo();

    Queue::assertPushed(RunScan::class, fn ($job) => $job->userId === auth()->id());
});
