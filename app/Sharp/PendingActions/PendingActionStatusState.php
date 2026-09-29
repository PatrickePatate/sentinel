<?php

namespace App\Sharp\PendingActions;

use Code16\Sharp\EntityList\Commands\EntityState;

/**
 * Display-only: transitions go through the approve / reject commands (ActionExecutor).
 */
class PendingActionStatusState extends EntityState
{
    protected function buildStates(): void
    {
        $this
            ->addState('pending', 'Pending', 'orange')
            ->addState('running', 'Running', 'blue')
            ->addState('executed', 'Executed', 'green')
            ->addState('rejected', 'Rejected', 'gray')
            ->addState('expired', 'Expired', 'gray')
            ->addState('stale', 'Stale', 'gray')
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
