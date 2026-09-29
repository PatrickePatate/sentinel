<?php

namespace App\Ssh\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class ServiceStatusTool implements Tool
{
    public function name(): string
    {
        return 'service_status';
    }

    public function description(): string
    {
        return 'Show the systemd status of one service (read-only).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()->description('Unit name, e.g. nginx or php8.5-fpm')->required(),
        ];
    }

    public function command(array $arguments): string
    {
        $service = $arguments['service'] ?? null;

        if (! is_string($service) || ! preg_match('/^[a-zA-Z0-9@._-]{1,64}$/', $service) || str_starts_with($service, '-')) {
            throw new InvalidToolArguments('Invalid service name.');
        }

        return 'systemctl status --no-pager --lines=20 -- '.escapeshellarg($service).' 2>&1';
    }
}
