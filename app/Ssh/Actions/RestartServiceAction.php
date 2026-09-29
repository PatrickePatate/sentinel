<?php

namespace App\Ssh\Actions;

use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class RestartServiceAction implements ActionTool
{
    public function name(): string
    {
        return 'restart_service';
    }

    public function description(): string
    {
        return 'Restart one systemd service. Only services on the configured allowlist. Always needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['service' => $schema->string()->description('Unit name from the allowlist')->required()];
    }

    public function command(array $arguments): string
    {
        $service = $arguments['service'] ?? null;

        if (! is_string($service) || ! in_array($service, config('sentinel.actions.restartable_services'), true)) {
            throw new InvalidToolArguments('Service is not on the restart allowlist.');
        }

        return 'systemctl restart -- '.escapeshellarg($service).' 2>&1';
    }
}
