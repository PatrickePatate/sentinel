<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class InstallSecurityPackageAction implements ActionTool, RequiresSudo
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-install-package';

    public function name(): string
    {
        return 'install_security_package';
    }

    public function description(): string
    {
        return 'Install one missing security tool ('.implode(', ', config('sentinel.actions.installable_packages')).') with apt, using its packaged default configuration. '
            .'Refuses anything else, and any install that would remove packages. Always needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['package' => $schema->string()->enum(array_values(config('sentinel.actions.installable_packages')))->required()];
    }

    public function command(array $arguments): string
    {
        $package = $arguments['package'] ?? null;

        if (! is_string($package) || ! in_array($package, config('sentinel.actions.installable_packages'), true)) {
            throw new InvalidToolArguments('This package cannot be installed by the agent.');
        }

        return $this->sudo().self::WRAPPER.' '.escapeshellarg($package).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
