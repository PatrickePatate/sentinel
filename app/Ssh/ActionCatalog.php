<?php

namespace App\Ssh;

use App\Ssh\Actions\ActionTool;
use App\Ssh\Actions\FixedCommandAction;
use App\Ssh\Actions\RestartServiceAction;
use App\Ssh\Actions\RiskLevel;
use App\Ssh\Actions\UpdatePackageAction;
use InvalidArgumentException;

/**
 * Corrective actions, kept apart from the read-only ToolCatalog.
 */
class ActionCatalog
{
    /** @var array<string, ActionTool> */
    private array $actions = [];

    /** @param iterable<ActionTool> $actions */
    public function __construct(iterable $actions)
    {
        foreach ($actions as $action) {
            $this->actions[$action->name()] = $action;
        }
    }

    public static function default(): self
    {
        return new self([
            new FixedCommandAction('vacuum_journal', 'Delete systemd journal entries older than 14 days.', 'journalctl --vacuum-time=14d 2>&1', RiskLevel::Low),
            new FixedCommandAction('clean_apt_cache', 'Clear the apt package download cache.', 'apt-get clean 2>&1', RiskLevel::Low),
            new RestartServiceAction,
            new UpdatePackageAction,
        ]);
    }

    public function get(string $name): ActionTool
    {
        return $this->actions[$name] ?? throw new InvalidArgumentException("Unknown action: {$name}");
    }

    /** @return array<string, ActionTool> */
    public function all(): array
    {
        return $this->actions;
    }
}
