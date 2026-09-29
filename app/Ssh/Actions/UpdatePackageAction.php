<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class UpdatePackageAction implements ActionTool, RequiresSudo
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-upgrade-package';

    public function name(): string
    {
        return 'update_package';
    }

    public function description(): string
    {
        return 'Upgrade one already-installed apt package to the latest available version. Never installs new packages, never removes any, and refuses sensitive packages (ssh, libc, systemd, kernel, databases...). Always needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['package' => $schema->string()->description('Debian package name, e.g. nginx or curl')->required()];
    }

    public function command(array $arguments): string
    {
        $package = $arguments['package'] ?? null;

        if (! is_string($package) || ! preg_match('/^[a-z0-9][a-z0-9+.-]{0,99}$/', $package)) {
            throw new InvalidToolArguments('Invalid package name.');
        }

        foreach (config('sentinel.actions.package_denylist') as $pattern) {
            if (fnmatch($pattern, $package)) {
                throw new InvalidToolArguments("Package {$package} is protected and cannot be upgraded by the agent.");
            }
        }

        // The root-owned wrapper re-validates the name and applies apt's safe flags itself.
        return $this->sudo().self::WRAPPER.' '.escapeshellarg($package).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
