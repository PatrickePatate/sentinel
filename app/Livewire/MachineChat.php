<?php

namespace App\Livewire;

use App\Ai\ScanRunner;
use App\Models\ChatMessage;
use App\Models\Machine;
use Illuminate\Support\Collection;
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

        $machine = Machine::findOrFail($this->machineId);
        $text = $this->message;
        $this->message = '';

        $history = $this->conversation()->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content])->all();

        $this->store('user', $text);

        $run = $runner->reply($machine, $text, $history);

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
