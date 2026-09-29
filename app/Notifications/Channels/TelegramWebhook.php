<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramWebhook
{
    public static function register(NotificationChannel $channel): string
    {
        $url = route('sentinel.telegram.webhook', $channel);

        if (! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException("Telegram only delivers to public HTTPS URLs (APP_URL is {$url}).");
        }

        $settings = $channel->settings;

        if (blank($settings['webhook_secret'] ?? null)) {
            $settings['webhook_secret'] = Str::random(48);
            $channel->update(['settings' => $settings]);
        }

        (new TelegramClient($channel))->setWebhook($url, $settings['webhook_secret']);

        return $url;
    }
}
