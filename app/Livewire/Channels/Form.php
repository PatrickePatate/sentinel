<?php

namespace App\Livewire\Channels;

use App\Ai\Severity;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\NotificationChannel;
use App\Notifications\Channels\TelegramClient;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

#[Layout('components.layouts.app')]
class Form extends Component
{
    use AuthorizesAdmin;

    #[Locked]
    public ?int $channelId = null;

    /** One-time code the person must send with /start; proves they control the bot chat being linked. */
    #[Locked]
    public string $start_code = '';

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
        $this->start_code = Str::lower(Str::random(8));

        if ($channel?->exists) {
            $this->channelId = $channel->id;
            $this->fill($channel->only(['name', 'type', 'min_severity', 'notify_scans', 'notify_approvals', 'enabled']));
            $this->email = (string) $channel->setting('email');
            $this->chat_id = (string) $channel->setting('chat_id');
            $this->approver_ids = (string) $channel->setting('approver_ids');
        }
    }

    /** Finds the chat that sent "/start <code>" to the bot so nobody has to dig the chat id out of getUpdates by hand. */
    public function detectChat(): void
    {
        $this->resetErrorBag('bot_token');

        $token = $this->bot_token;

        if ($token === '' && $this->channelId) {
            $token = (string) NotificationChannel::find($this->channelId)?->setting('bot_token');
        }

        if (! preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', $token)) {
            $this->addError('bot_token', 'Enter the bot token first.');

            return;
        }

        try {
            $chat = TelegramClient::withToken($token)->latestChat($this->start_code);
        } catch (Throwable) {
            // The exception message may carry API details; keep it generic.
            $this->addError('bot_token', 'Telegram refused the request: check the token, and that no webhook is registered for this bot (it blocks getUpdates).');

            return;
        }

        if (! $chat) {
            $this->addError('chat_id', "No matching message found. Send \"/start {$this->start_code}\" to your bot (or in a group it belongs to), then try again.");

            return;
        }

        $this->chat_id = $chat['chat_id'];

        if (blank($this->approver_ids)) {
            $this->approver_ids = $chat['user_id'];
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
