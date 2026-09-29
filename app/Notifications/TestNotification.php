<?php

namespace App\Notifications;

use App\Models\NotificationChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestNotification extends Notification
{
    public function via(NotificationChannel $notifiable): array
    {
        return [$notifiable->type === 'telegram' ? TelegramChannel::class : 'mail'];
    }

    public function toMail(NotificationChannel $notifiable): MailMessage
    {
        return (new MailMessage)->subject('[Sentinel] Test notification')->line('This channel works.');
    }

    public function toTelegram(NotificationChannel $notifiable): TelegramMessage
    {
        return new TelegramMessage('✅ <b>Sentinel</b>: this channel works.');
    }
}
