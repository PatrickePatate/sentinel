<?php

namespace App\Ssh;

use App\Ssh\Actions\ActionTool;
use App\Ssh\Actions\Fail2banCreateJailAction;
use App\Ssh\Actions\Fail2banRemoveCustomAction;
use App\Ssh\Actions\Fail2banUnbanAction;
use App\Ssh\Actions\Fail2banWriteFilterAction;
use App\Ssh\Actions\FixedCommandAction;
use App\Ssh\Actions\HardenSshAction;
use App\Ssh\Actions\InstallSecurityPackageAction;
use App\Ssh\Actions\ReloadServiceAction;
use App\Ssh\Actions\ResetFailedUnitAction;
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
            new FixedCommandAction('vacuum_journal', 'Delete systemd journal entries older than 14 days.', '{sudo}journalctl --vacuum-time=14d 2>&1', RiskLevel::Low, ['/usr/bin/journalctl --vacuum-time=14d']),
            new FixedCommandAction('clean_apt_cache', 'Clear the apt package download cache.', '{sudo}apt-get clean 2>&1', RiskLevel::Low, ['/usr/bin/apt-get clean']),
            new FixedCommandAction('refresh_package_lists', 'Run apt-get update: refresh the package lists (needed before checking or installing security updates).', '{sudo}apt-get update 2>&1', RiskLevel::Low, ['/usr/bin/apt-get update']),
            new FixedCommandAction('renew_certificates', 'Run certbot renew: renews Let\'s Encrypt certificates that are close to expiry (existing deploy hooks apply). Needs human approval.', '{sudo}certbot renew 2>&1', RiskLevel::Medium, ['/usr/bin/certbot renew']),
            new ResetFailedUnitAction,
            new ReloadServiceAction,
            new RestartServiceAction,
            new Fail2banUnbanAction,
            new Fail2banWriteFilterAction,
            new Fail2banCreateJailAction,
            new Fail2banRemoveCustomAction,
            new UpdatePackageAction,
            new InstallSecurityPackageAction,
            new HardenSshAction,
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
