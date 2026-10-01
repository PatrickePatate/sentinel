<?php

namespace App\Livewire\Channels;

use App\Ai\Severity;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\NotificationChannel;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Form extends Component
{
    use AuthorizesAdmin;

    #[Locked]
    public ?int $channelId = null;

    public string $name = '';

    public string $type = 'telegram';

    public string $email = '';

    /** Write-only: never sent back to the browser. */
    public string $bot_token = '';

    public string $chat_id = '';

    public string $approver_ids = '';

    public string $min_severity = 'medium';

    public bool $notify_scans = true;

    public bool $notify_approvals = true;

    public bool $enabled = true;

    public function mount(?NotificationChannel $channel = null): void
    {
        if ($channel?->exists) {
            $this->channelId = $channel->id;
            $this->fill($channel->only(['name', 'type', 'min_severity', 'notify_scans', 'notify_approvals', 'enabled']));
            $this->email = (string) $channel->setting('email');
            $this->chat_id = (string) $channel->setting('chat_id');
            $this->approver_ids = (string) $channel->setting('approver_ids');
        }
    }

    public function save()
    {
        $channel = $this->channelId ? NotificationChannel::findOrFail($this->channelId) : new NotificationChannel;
        $settings = $channel->settings ?? [];

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(NotificationChannel::TYPES))],
            'email' => ['required_if:type,mail', 'nullable', 'email', 'max:255'],
            'bot_token' => [$this->type === 'telegram' && blank($settings['bot_token'] ?? null) ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[0-9]+:[A-Za-z0-9_-]+$/'],
            'chat_id' => ['required_if:type,telegram', 'nullable', 'regex:/^-?[0-9]+$/'],
            'approver_ids' => ['nullable', 'regex:/^[0-9,\s]*$/'],
            'min_severity' => ['required', Rule::in(Severity::values())],
            'notify_scans' => ['boolean'],
            'notify_approvals' => ['boolean'],
            'enabled' => ['boolean'],
        ]);

        $settings = $validated['type'] === 'mail'
            ? ['email' => $validated['email']]
            : [
                'bot_token' => $validated['bot_token'] ?: ($settings['bot_token'] ?? null),
                'chat_id' => $validated['chat_id'],
                'approver_ids' => $validated['approver_ids'] ?? '',
                'webhook_secret' => $settings['webhook_secret'] ?? null,
            ];

        $channel->fill([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'min_severity' => $validated['min_severity'],
            'notify_scans' => $validated['notify_scans'],
            'notify_approvals' => $validated['notify_approvals'],
            'enabled' => $validated['enabled'],
            'settings' => $settings,
        ])->save();

        session()->flash('toast', ['message' => 'Channel saved', 'type' => 'success']);

        return $this->redirectRoute('channels.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.channels.form', [
            'severities' => collect(Severity::cases())->mapWithKeys(fn (Severity $s) => [$s->value => ucfirst($s->value)])->all(),
            'types' => NotificationChannel::TYPES,
            'hasToken' => $this->channelId && filled(NotificationChannel::find($this->channelId)?->setting('bot_token')),
        ])->title($this->channelId ? 'Edit channel' : 'New channel');
    }
}
