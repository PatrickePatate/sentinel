<?php

namespace App\Sharp\PendingActions;

use App\Models\PendingAction;
use App\Ssh\ActionExecutor;
use Code16\Sharp\EntityList\Commands\InstanceCommand;

class ApprovePendingActionCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Approve and run';
    }

    public function buildCommandConfig(): void
    {
        $this->configureConfirmationText('Run this exact command on the machine now?');
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        app(ActionExecutor::class)->approve(PendingAction::findOrFail($instanceId));

        return $this->refresh($instanceId);
    }

    public function authorizeFor(mixed $instanceId): bool
    {
        return PendingAction::whereKey($instanceId)->where('status', 'pending')->exists();
    }
}
