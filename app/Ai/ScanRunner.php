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
    /** Creates the run up front so the UI can point at it while the job is still waiting for a worker. */
    public function queue(Machine $machine, string $objective, string $trigger = 'manual'): AgentRun
    {
        return AgentRun::create([
            'machine_id' => $machine->id,
            'provider' => config('sentinel.agent.provider'),
            'objective' => $objective,
            'trigger' => $trigger,
            'status' => 'queued',
        ]);
    }

    public function run(Machine $machine, string $objective, ?string $provider = null, ?string $model = null, string $trigger = 'manual', ?AgentRun $run = null): AgentRun
    {
        return $this->execute($machine, $objective, "Machine: {$machine->name} ({$machine->environment}).\nObjective: {$objective}", [], $provider, $model, trigger: $trigger, scan: true, run: $run);
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
    private function execute(Machine $machine, string $objective, string $prompt, array $history, ?string $provider = null, ?string $model = null, ?Closure $onStream = null, string $trigger = 'chat', bool $scan = false, ?AgentRun $run = null): AgentRun
    {
        if ($trigger === 'scheduled') {
            // A scheduled-only model is only used together with its own provider: a model name means nothing on another one.
            $scheduledProvider = config('sentinel.agent.scheduled_provider');
            $provider ??= $scheduledProvider;
            $model ??= $scheduledProvider ? config('sentinel.agent.scheduled_model') : null;
        }

        $provider ??= config('sentinel.agent.provider');
        $model ??= config('sentinel.agent.model');

        if ($run) {
            $run->update(['provider' => $provider, 'status' => 'running']);
        } else {
            $run = AgentRun::create([
                'machine_id' => $machine->id,
                'provider' => $provider,
                'objective' => $objective,
                'trigger' => $trigger,
                'status' => 'running',
            ]);
        }

        try {
            $agent = new SysadminAgent($machine, $run, $objective, $history, requiresVerdict: $scan);

            if ($onStream || $scan) {
                $stream = $agent->stream($prompt, provider: $provider, model: $model);
                $live = new LiveReport($run);

                foreach ($stream as $event) {
                    if ($scan) {
                        $live->handle($event);
                    }

                    if ($onStream) {
                        $onStream($event);
                    }
                }
                $text = $stream->text;
            } else {
                $text = $agent->prompt($prompt, provider: $provider, model: $model)->text;
            }

            $run->update(['status' => 'completed', 'report' => $text, 'progress' => null]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'report' => $e->getMessage(), 'progress' => null]);
        }

        return $run;
    }
}
