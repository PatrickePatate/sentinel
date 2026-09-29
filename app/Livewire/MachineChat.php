<?php

namespace App\Livewire;

use App\Ai\ScanRunner;
use App\Models\ChatMessage;
use App\Models\Machine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.chat')]
class MachineChat extends Component
{
    #[Locked]
    public int $machineId;

    public string $message = '';

    public function mount(Machine $machine): void
    {
        $this->machineId = $machine->id;
    }

    public function send(ScanRunner $runner): void
    {
        $this->validate(['message' => ['required', 'string', 'max:2000']]);

        $throttleKey = 'chat:'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttleKey, config('sentinel.limits.chat_messages_per_minute'))) {
            $this->addError('message', 'Too many messages, wait a moment.');

            return;
        }

        RateLimiter::hit($throttleKey, 60);

        $machine = Machine::findOrFail($this->machineId);
        $text = $this->message;
        $this->message = '';

        $history = $this->conversation()->take(-config('sentinel.limits.chat_history_messages'))->values()->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content])->all();

        $this->store('user', $text);

        // wire:stream inserts raw HTML: everything coming from the user or the model MUST be escaped.
        $this->stream(to: 'question', content: e($text), replace: true);
        $this->stream(to: 'status', content: e('The agent is thinking…'), replace: true);

        $run = $runner->reply($machine, $text, $history, function (StreamEvent $event) {
            if ($event instanceof TextDelta) {
                $this->stream(to: 'answer', content: e($event->delta));
            } elseif ($event instanceof ToolCall) {
                $this->stream(to: 'status', content: e("Running {$event->toolCall->name}…"), replace: true);
            }
        });

        $this->store('assistant', $run->status === 'completed' ? $run->report : 'The agent could not answer: '.$run->report);
    }

    public function clear(): void
    {
        ChatMessage::where('machine_id', $this->machineId)->where('user_id', auth()->id())->delete();
    }

    /** @return Collection<int, ChatMessage> */
    private function conversation(): Collection
    {
        return ChatMessage::where('machine_id', $this->machineId)
            ->where('user_id', auth()->id())
            ->orderBy('id')
            ->get();
    }

    private function store(string $role, string $content): void
    {
        ChatMessage::create([
            'machine_id' => $this->machineId,
            'user_id' => auth()->id(),
            'role' => $role,
            'content' => $content,
        ]);
    }

    public function render()
    {
        return view('livewire.machine-chat', [
            'machine' => Machine::findOrFail($this->machineId),
            'messages' => $this->conversation(),
        ]);
    }
}
