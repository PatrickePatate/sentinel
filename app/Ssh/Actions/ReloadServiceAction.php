<?php

namespace App\Ssh\Actions;

class ReloadServiceAction extends SystemctlServiceAction
{
    public function name(): string
    {
        return 'reload_service';
    }

    public function description(): string
    {
        return 'Reload the configuration of one systemd service without stopping it. Only services on the reload allowlist. Needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    protected function verb(): string
    {
        return 'reload';
    }

    protected function allowedServices(): array
    {
        return self::list('reloadable_services');
    }
}
