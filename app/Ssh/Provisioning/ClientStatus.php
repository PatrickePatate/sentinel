<?php

namespace App\Ssh\Provisioning;

/** Where a machine stands compared to the client bundle this Sentinel would install today. */
readonly class ClientStatus
{
    public const UP_TO_DATE = 'up_to_date';

    public const OUTDATED = 'outdated';

    /** The root updater itself changed: only the provisioning one-liner (run as root on the machine) can replace it. */
    public const UPDATER_OUTDATED = 'updater_outdated';

    public const NOT_INSTALLED = 'not_installed';

    public const UNREACHABLE = 'unreachable';

    public function __construct(
        public string $state,
        public string $expected,
        public ?string $installed = null,
        public ?string $error = null,
    ) {}

    public function isUpToDate(): bool
    {
        return $this->state === self::UP_TO_DATE;
    }

    public function canUpdateRemotely(): bool
    {
        return $this->state === self::OUTDATED;
    }

    public function label(): string
    {
        return match ($this->state) {
            self::UP_TO_DATE => 'Up to date',
            self::OUTDATED => 'Update available',
            self::UPDATER_OUTDATED => 'Re-provision needed',
            self::NOT_INSTALLED => 'Not provisioned',
            default => 'Unreachable',
        };
    }
}
