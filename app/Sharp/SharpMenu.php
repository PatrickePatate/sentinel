<?php

namespace App\Sharp;

use App\Models\PendingAction;
use App\Sharp\Entities\AgentRunEntity;
use App\Sharp\Entities\AuditEntryEntity;
use App\Sharp\Entities\MachineEntity;
use App\Sharp\Entities\PendingActionEntity;
use Code16\Sharp\Utils\Menu\SharpMenu as BaseSharpMenu;

class SharpMenu extends BaseSharpMenu
{
    public function build(): self
    {
        return $this
            ->addEntityLink(MachineEntity::class, 'Machines', 'fas fa-server')
            ->addEntityLink(AgentRunEntity::class, 'Scans', 'fas fa-magnifying-glass')
            ->addEntityLink(
                PendingActionEntity::class,
                'Pending actions',
                'fas fa-hand',
                badge: fn () => PendingAction::where('status', 'pending')->count() ?: null,
            )
            ->addEntityLink(AuditEntryEntity::class, 'Audit log', 'fas fa-clipboard-list');
    }
}
