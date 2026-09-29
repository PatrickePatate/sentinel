<?php

namespace App\Ssh\Actions;

class ResetFailedUnitAction extends SystemctlServiceAction
{
    public function name(): string
    {
        return 'reset_failed_unit';
    }

    public function description(): string
    {
        return 'Clear the "failed" state of a systemd unit (does not start or stop anything). Only allowlisted units.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    protected function verb(): string
    {
        return 'reset-failed';
    }

    protected function allowedServices(): array
    {
        return array_values(array_unique([...self::list('restartable_services'), ...self::list('reloadable_services')]));
    }
}
