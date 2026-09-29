<?php

namespace App\Console\Commands;

use App\Models\NotificationChannel;
use App\Notifications\Channels\TelegramWebhook;
use Illuminate\Console\Command;
use Throwable;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'sentinel:telegram-webhook {channel : Notification channel id}';

    protected $description = 'Register the webhook that lets Telegram approve/reject buttons reach Sentinel';

    public function handle(): int
    {
        $channel = NotificationChannel::where('type', 'telegram')->findOrFail($this->argument('channel'));

        try {
            $this->info('Webhook registered: '.TelegramWebhook::register($channel));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
