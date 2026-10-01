<?php

namespace App\Support;

use App\Events\SentinelUpdated;
use Illuminate\Support\Facades\Cache;
use Throwable;

class Realtime
{
    public static function enabled(): bool
    {
        return (bool) config('sentinel.realtime.enabled');
    }

    /** Never throws: a stopped WebSocket server must not break a scan or an approval. The dashboard falls back to its slow poll. */
    public static function push(string $topic, ?int $id = null): void
    {
        // After a failure, stay quiet for a minute: a dead server must not slow every update down.
        if (! self::enabled() || Cache::has('sentinel.realtime.down')) {
            return;
        }

        try {
            SentinelUpdated::dispatch($topic, $id);
        } catch (Throwable $e) {
            Cache::put('sentinel.realtime.down', true, 60);
            report($e);
        }
    }

    /** Poll interval for a view: fast without WebSockets, a safety net with them. */
    public static function poll(string $fast, string $slow = '30s'): string
    {
        return self::enabled() ? $slow : $fast;
    }
}
