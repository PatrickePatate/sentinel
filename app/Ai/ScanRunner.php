<?php

namespace App\Ai;

use App\Ai\Agents\SysadminAgent;
use App\Models\AgentRun;
use App\Models\Machine;
use Closure;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Throwable;

class ScanRunner
{
    public function run(Machine $machine, string $objective, ?string $provider = null, ?string $model = null, string $trigger = 'manual'): AgentRun
    {
        return $this->execute($machine, $objective, "Machine: {$machine->name} ({$machine->environment}).\nObjective: {$objective}", [], $provider, $model, trigger: $trigger, scan: true);
    }

    /**
     * One chat turn. Each turn gets its own AgentRun so the audit trail and the
     * per-run autonomous-action quota keep applying.
     *
     * @param  list<array{role: string, content: string}>  $history
     * @param  (Closure(StreamEvent): void)|null  $onStream  Receives streaming events (text deltas, tool calls...).
     */
    public function reply(Machine $machine, string $message, array $history, ?Closure $onStream = null): AgentRun
    {
        return $this->execute($machine, $message, $message, $history, onStream: $onStream);
    }

    /** @param list<array{role: string, content: string}> $history */
    private function execute(Machine $machine, string $objective, string $prompt, array $history, ?string $provider = null, ?string $model = null, ?Closure $onStream = null, string $trigger = 'chat', bool $scan = false): AgentRun
    {
        $provider ??= config('sentinel.agent.provider');
        $model ??= config('sentinel.agent.model');

        $run = AgentRun::create([
            'machine_id' => $machine->id,
            'provider' => $provider,
            'objective' => $objective,
            'trigger' => $trigger,
        ]);

        try {
            $agent = new SysadminAgent($machine, $run, $objective, $history, requiresVerdict: $scan);

            if ($onStream) {
                $stream = $agent->stream($prompt, provider: $provider, model: $model);
                foreach ($stream as $event) {
                    $onStream($event);
                }
                $text = $stream->text;
            } else {
                $text = $agent->prompt($prompt, provider: $provider, model: $model)->text;
            }

            $run->update(['status' => 'completed', 'report' => $text]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'report' => $e->getMessage()]);
        }

        return $run;
    }
}
