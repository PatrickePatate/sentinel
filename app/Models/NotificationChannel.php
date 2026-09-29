<?php

namespace App\Models;

use App\Ai\Severity;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/** A place humans get told about suspicious scans and actions waiting for approval. Acts as the notifiable. */
#[Hidden(['settings'])]
class NotificationChannel extends Model
{
    use Notifiable;

    public const TYPES = ['mail' => 'Email', 'telegram' => 'Telegram'];

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'settings', 'min_severity', 'notify_scans', 'notify_approvals', 'enabled'];

    protected function casts(): array
    {
        return [
            'settings' => 'encrypted:array',
            'notify_scans' => 'boolean',
            'notify_approvals' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public function minSeverity(): Severity
    {
        return Severity::tryFrom($this->min_severity) ?? Severity::Medium;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->setting('email');
    }

    public function routeNotificationForTelegram(): ?string
    {
        return $this->setting('chat_id');
    }

    /** Telegram user ids allowed to press the Approve / Reject buttons. Defaults to the chat itself (private chats). */
    /** @return list<string> */
    public function approverIds(): array
    {
        $ids = preg_split('/[\s,]+/', (string) $this->setting('approver_ids', ''), -1, PREG_SPLIT_NO_EMPTY);

        $chatId = (string) $this->setting('chat_id');

        if ($ids === [] && preg_match('/^\d+$/', $chatId)) {
            $ids = [$chatId]; // a positive chat id is a private chat: its only member is the user
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }
}
