<?php

namespace App\Ssh;

use App\Models\AgentRun;
use App\Models\Machine;

class AuditTrail
{
    /** @param array<string, mixed> $properties */
    public function record(Machine $machine, ?AgentRun $run, string $event, string $description, array $properties = []): void
    {
        $logger = activity('ssh')->performedOn($machine);

        if (auth()->check()) {
            $logger->causedBy(auth()->user());
        }

        $logger
            ->event($event)
            ->withProperties($properties + ['agent_run_id' => $run?->id])
            ->log($description);
    }
}
