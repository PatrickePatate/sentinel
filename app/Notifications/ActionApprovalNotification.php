<?php

namespace App\Notifications;

use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\TelegramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PendingAction $pending) {}

    public function via(NotificationChannel $notifiable): array
    {
        return [$notifiable->type === 'telegram' ? TelegramChannel::class : 'mail'];
    }

    public function toMail(NotificationChannel $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[Sentinel] Approval needed on {$this->pending->machine->name}")
            ->line("The agent wants to run a {$this->pending->risk}-risk action on {$this->pending->machine->name}.")
            ->line("Command: {$this->pending->command}")
            ->line("Why it is held: {$this->pending->reason}")
            ->line("Pending action #{$this->pending->id} expires in ".config('sentinel.gate.pending_ttl_hours').'h. Approve or reject it in the Sentinel back-office.');
    }

    public function toTelegram(NotificationChannel $notifiable): TelegramMessage
    {
        $p = $this->pending;

        return new TelegramMessage(
            sprintf(
                "🛡️ <b>Approval needed</b> on <b>%s</b> (%s)\nAction: <code>%s</code> · risk %s\n\n<pre>%s</pre>\nWhy held: %s\n\n<i>Expires in %dh · #%d</i>",
                e($p->machine->name), e($p->machine->environment), e($p->action), e($p->risk), e(mb_substr($p->command, 0, 1000)),
                e(mb_substr((string) $p->reason, 0, 500)), config('sentinel.gate.pending_ttl_hours'), $p->id,
            ),
            [[
                ['text' => '✅ Approve and run', 'callback_data' => "approve:{$p->id}"],
                ['text' => '✖ Reject', 'callback_data' => "reject:{$p->id}"],
            ]],
        );
    }
}
