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
        $provider ??= config('sentinel.agent.provider');
        $model ??= config('sentinel.agent.model');

        $run = AgentRun::create([
            'machine_id' => $machine->id,
            'provider' => $provider,
            'objective' => $objective,
        ]);

        try {
            $response = (new SysadminAgent($machine, $run))->prompt(
                "Machine: {$machine->name} ({$machine->environment}).\nObjective: {$objective}",
                provider: $provider,
                model: $model,
            );

            $run->update(['status' => 'completed', 'report' => $response->text]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'report' => $e->getMessage()]);
        }

        return $run;
    }
}
