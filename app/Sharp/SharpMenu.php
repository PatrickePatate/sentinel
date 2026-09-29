<?php

namespace App\Sharp;

use App\Models\PendingAction;
use App\Sharp\Entities\AgentRunEntity;
use App\Sharp\Entities\AuditEntryEntity;
use App\Sharp\Entities\MachineEntity;
use App\Sharp\Entities\NotificationChannelEntity;
use App\Sharp\Entities\PendingActionEntity;
use Code16\Sharp\Utils\Menu\SharpMenu as BaseSharpMenu;
use Code16\Sharp\Utils\Menu\SharpMenuItemSection;

class SharpMenu extends BaseSharpMenu
{
    public function build(): self
    {
        return $this
            ->addSection('Infrastructure', fn (SharpMenuItemSection $section) => $section
                ->addEntityLink(MachineEntity::class, 'Machines', 'lucide-server')
            )
            ->addSection('AI agent', fn (SharpMenuItemSection $section) => $section
                ->addEntityLink(AgentRunEntity::class, 'Scans', 'lucide-scan-search')
                ->addEntityLink(
                    PendingActionEntity::class,
                    'Pending actions',
                    'lucide-hand',
                    badge: fn () => PendingAction::where('status', 'pending')->count() ?: null,
                )
            )
            ->addSection('Settings', fn (SharpMenuItemSection $section) => $section
                ->addEntityLink(NotificationChannelEntity::class, 'Notifications', 'lucide-bell')
            )
            ->addSection('Traceability', fn (SharpMenuItemSection $section) => $section
                ->addEntityLink(AuditEntryEntity::class, 'Audit log', 'lucide-clipboard-list')
            );
    }
}
