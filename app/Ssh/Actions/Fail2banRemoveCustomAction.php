<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class Fail2banRemoveCustomAction implements ActionTool, RequiresSudo
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-fail2ban-remove';

    public function name(): string
    {
        return 'fail2ban_remove_custom';
    }

    public function description(): string
    {
        return 'Remove a custom jail and filter created by Sentinel (only files named sentinel-<name>). Rollback for the two actions above. Needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->description('Slug used when creating the filter/jail')->required()];
    }

    public function command(array $arguments): string
    {
        $name = $arguments['name'] ?? null;

        if (! is_string($name) || ! preg_match('/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/', $name)) {
            throw new InvalidToolArguments('Invalid name.');
        }

        return $this->sudo().self::WRAPPER.' '.escapeshellarg($name).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
