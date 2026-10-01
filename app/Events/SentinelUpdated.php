<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** "Something changed": the dashboard re-reads its own data. It deliberately carries nothing sensitive. */
class SentinelUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $topic, public ?int $id = null) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('sentinel')];
    }

    public function broadcastAs(): string
    {
        return 'Updated';
    }
}
