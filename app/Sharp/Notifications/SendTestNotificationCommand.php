<?php

namespace App\Sharp\Notifications;

use App\Models\NotificationChannel;
use App\Notifications\TestNotification;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Throwable;

class SendTestNotificationCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Send a test';
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        try {
            // Sent synchronously so a wrong token or address is reported right here.
            NotificationChannel::findOrFail($instanceId)->notifyNow(new TestNotification);
        } catch (Throwable $e) {
            report($e);

            return $this->info('Sending failed: '.$e->getMessage());
        }

        return $this->info('Test notification sent.');
    }
}
