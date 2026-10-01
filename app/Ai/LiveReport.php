<?php

namespace App\Ai;

use App\Models\AgentRun;
use App\Support\Realtime;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;

/**
 * Persists what the agent is writing (throttled) so a UI in another process can show it while the scan runs on a worker.
 */
class LiveReport
{
    private string $buffer = '';

    private float $lastWrite = 0.0;

    public function __construct(private AgentRun $run, private float $interval = 0.4) {}

    public function handle(StreamEvent $event): void
    {
        if ($event instanceof TextDelta) {
            $this->buffer .= $event->delta;

            if (microtime(true) - $this->lastWrite >= $this->interval) {
                $this->lastWrite = microtime(true);
                $this->run->update(['report' => $this->buffer]);
                Realtime::push('run', $this->run->id);
            }
        } elseif ($event instanceof ToolCall) {
            // Text written before a tool call is a separate paragraph from what follows it.
            if ($this->buffer !== '' && ! str_ends_with($this->buffer, "\n")) {
                $this->buffer .= "\n\n";
            }

            $this->lastWrite = microtime(true);
            $this->run->update(['report' => $this->buffer ?: null, 'progress' => "Running {$event->toolCall->name}…"]);
            Realtime::push('run', $this->run->id);
        }
    }
}
