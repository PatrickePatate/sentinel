<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramWebhook
{
    public static function register(NotificationChannel $channel): string
    {
        // From the back-office, trust the host the admin is actually browsing on (APP_URL is often a stale http://localhost).
        $path = route('sentinel.telegram.webhook', $channel, false);
        $url = app()->runningInConsole() ? route('sentinel.telegram.webhook', $channel) : request()->getSchemeAndHttpHost().$path;

        if (! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException("Telegram only delivers to public HTTPS URLs (got {$url}; open the back-office through its public HTTPS address, or fix APP_URL for the CLI).");
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
