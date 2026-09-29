<?php

namespace App\Sharp\Scans;

use Code16\Sharp\EntityList\Commands\EntityState;

/**
 * Display-only: a run's status is driven by the ScanRunner, never edited by hand.
 */
class AgentRunStatusState extends EntityState
{
    protected function buildStates(): void
    {
        $this
            ->addState('running', 'Running', 'orange')
            ->addState('completed', 'Completed', 'green')
            ->addState('failed', 'Failed', 'red');
    }

    public function authorize(): bool
    {
        return false;
    }

    protected function updateState($instanceId, string $stateId): ?array
    {
        return null;
    }
}
