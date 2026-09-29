<?php

use App\Ai\Agents\SysadminAgent;
use App\Livewire\MachineChat;
use App\Models\AgentRun;
use App\Models\ChatMessage;
use App\Models\Machine;
use App\Models\User;
use App\Sharp\Entities\MachineEntity;
use Code16\Sharp\Utils\Testing\SharpAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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

it('honours the Sharp viewSharp gate on the chat', function () {
    Gate::define('viewSharp', fn () => false);
    $this->actingAs(User::factory()->create());

    $this->get(route('sentinel.chat', Machine::factory()->create()))->assertRedirect();
});

it('can only be framed by the same origin', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('sentinel.chat', Machine::factory()->create()))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('is embedded as an iframe in the machine show page', function () {
    $this->actingAs(User::factory()->create());
    $machine = Machine::factory()->create();

    $this->sharpShow(MachineEntity::class, $machine->id)->get()
        ->assertShowData(fn ($json) => $json->where('chat', fn ($html) => str_contains($html, '<iframe src="/chat/'.$machine->id.'"'))->etc());
});

it('answers through the agent and keeps the conversation per user', function () {
    SysadminAgent::fake(['Disk usage is fine.']);
    $user = User::factory()->create();
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

    $other = User::factory()->create();
    $this->actingAs($other);
    Livewire::test(MachineChat::class, ['machine' => $machine])->assertDontSee('How is the disk?');
});

it('validates and escapes messages', function () {
    $this->actingAs(User::factory()->create());
    $machine = Machine::factory()->create();
    ChatMessage::create(['machine_id' => $machine->id, 'user_id' => auth()->id(), 'role' => 'assistant', 'content' => '<script>alert(1)</script>']);

    Livewire::test(MachineChat::class, ['machine' => $machine])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->set('message', '')->call('send')->assertHasErrors('message');
});
