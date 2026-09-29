<?php

namespace App\Ai;

use App\Ai\Agents\SysadminAgent;
use App\Models\AgentRun;
use App\Models\Machine;
use Throwable;

class ScanRunner
{
    public function run(Machine $machine, string $objective, ?string $provider = null, ?string $model = null): AgentRun
    {
        return $this->execute($machine, $objective, "Machine: {$machine->name} ({$machine->environment}).\nObjective: {$objective}", [], $provider, $model);
    }

    /**
     * One chat turn. Each turn gets its own AgentRun so the audit trail and the
     * per-run autonomous-action quota keep applying.
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    public function reply(Machine $machine, string $message, array $history): AgentRun
    {
        return $this->execute($machine, $message, $message, $history);
    }

    /** @param list<array{role: string, content: string}> $history */
    private function execute(Machine $machine, string $objective, string $prompt, array $history, ?string $provider = null, ?string $model = null): AgentRun
    {
        $provider ??= config('sentinel.agent.provider');
        $model ??= config('sentinel.agent.model');

        $run = AgentRun::create([
            'machine_id' => $machine->id,
            'provider' => $provider,
            'objective' => $objective,
        ]);

        try {
            $response = (new SysadminAgent($machine, $run, $objective, $history))->prompt($prompt, provider: $provider, model: $model);

            $run->update(['status' => 'completed', 'report' => $response->text]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'report' => $e->getMessage()]);
        }

        return $run;
    }
}
