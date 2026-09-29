<?php

namespace App\Sharp\Notifications;

use App\Models\NotificationChannel;
use App\Notifications\Channels\TelegramWebhook;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Throwable;

class RegisterTelegramWebhookCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Register Telegram webhook';
    }

    public function buildCommandConfig(): void
    {
        $this->configureDescription('Required once for the Approve / Reject buttons to work. Needs a public HTTPS APP_URL.');
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        try {
            TelegramWebhook::register(NotificationChannel::where('type', 'telegram')->findOrFail($instanceId));
        } catch (Throwable $e) {
            return $this->info('Registration failed: '.$e->getMessage());
        }

        return $this->info('Webhook registered.');
    }

    public function authorizeFor(mixed $instanceId): bool
    {
        return NotificationChannel::whereKey($instanceId)->where('type', 'telegram')->exists();
    }
}
