<?php

namespace App\Sharp\PendingActions;

use App\Models\PendingAction;
use App\Ssh\ActionExecutor;
use Code16\Sharp\EntityList\Commands\InstanceCommand;

class RejectPendingActionCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Reject';
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        app(ActionExecutor::class)->reject(PendingAction::findOrFail($instanceId));

        return $this->refresh($instanceId);
    }

    public function authorizeFor(mixed $instanceId): bool
    {
        return PendingAction::whereKey($instanceId)->where('status', 'pending')->exists();
    }
}
