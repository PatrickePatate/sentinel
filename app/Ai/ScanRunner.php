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
        return $this->execute($machine, $objective, $this->scanPrompt($machine, $objective), [], $provider, $model, trigger: $trigger, scan: true, run: $run);
    }

    /** Queues a follow-up turn on a finished scan ("fix what you found"), threaded under it. */
    public function queueFollowUp(AgentRun $scan, string $message): AgentRun
    {
        return AgentRun::create([
            'machine_id' => $scan->machine_id,
            'parent_run_id' => $scan->id,
            'provider' => config('sentinel.agent.provider'),
            'objective' => $message,
            'trigger' => 'follow_up',
            'status' => 'queued',
        ]);
    }

    /**
     * Runs a queued follow-up with the scan and the earlier follow-ups as conversation, so the agent can act on
     * what it reported. Its actions still go through the gate, and are linked to this follow-up run.
     */
    public function followUp(AgentRun $run): AgentRun
    {
        $scan = $run->parent;
        $history = [['role' => 'user', 'content' => $this->scanPrompt($scan->machine, $scan->objective)]];

        foreach ([$scan, ...$scan->followUps()->where('id', '<', $run->id)->where('status', 'completed')->get()] as $turn) {
            if ($turn->isNot($scan)) {
                $history[] = ['role' => 'user', 'content' => $turn->objective];
            }

            $history[] = ['role' => 'assistant', 'content' => filled($turn->report) ? $turn->report : '(no report)'];
        }

        $history = array_slice($history, -config('sentinel.limits.chat_history_messages'));

        return $this->execute($run->machine, $run->objective, $run->objective, $history, trigger: 'follow_up', live: true, run: $run);
    }

    private function scanPrompt(Machine $machine, string $objective): string
    {
        return "Machine: {$machine->name} ({$machine->environment}).\nObjective: {$objective}";
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
    private function execute(Machine $machine, string $objective, string $prompt, array $history, ?string $provider = null, ?string $model = null, ?Closure $onStream = null, string $trigger = 'chat', bool $scan = false, ?AgentRun $run = null, bool $live = false): AgentRun
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

            if ($onStream || $scan || $live) {
                $stream = $agent->stream($prompt, provider: $provider, model: $model);
                $report = new LiveReport($run);

                foreach ($stream as $event) {
                    if ($scan || $live) {
                        $report->handle($event);
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
