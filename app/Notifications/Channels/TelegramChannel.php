<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use Illuminate\Notifications\Notification;

class TelegramChannel
{
    public function send(NotificationChannel $notifiable, Notification $notification): void
    {
        /** @var TelegramMessage $message */
        $message = $notification->toTelegram($notifiable);

        (new TelegramClient($notifiable))->sendMessage($message->html, $message->keyboard);
    }
}
