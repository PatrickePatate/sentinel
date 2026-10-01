<?php

namespace App\Notifications;

use App\Models\Machine;
use App\Models\NotificationChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\TelegramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A short alert about a machine that did not come from a scan: a site went down, a certificate is about to expire... */
class MachineAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Machine $machine, public string $title, public string $body, public bool $urgent = true) {}

    public function via(NotificationChannel $notifiable): array
    {
        return [$notifiable->type === 'telegram' ? TelegramChannel::class : 'mail'];
    }

    public function toMail(NotificationChannel $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Sentinel]'.($this->urgent ? '[URGENT] ' : ' ').$this->title)
            ->line("Machine: {$this->machine->name} ({$this->machine->environment})")
            ->line($this->body);
    }

    public function toTelegram(NotificationChannel $notifiable): TelegramMessage
    {
        return new TelegramMessage(sprintf("%s <b>%s</b>\n%s\n\n<i>%s</i>", $this->urgent ? '🚨' : '⚠️', e($this->title), e(mb_substr($this->body, 0, 1500)), e($this->machine->name)));
    }
}
