<?php

namespace App\Ssh\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class ServiceLogsTool implements Tool
{
    public function name(): string
    {
        return 'service_logs';
    }

    public function description(): string
    {
        return 'Recent journal lines of one systemd service (read-only): use it to find out why a service failed or misbehaves.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()->description('Unit name, e.g. nginx or fail2ban')->required(),
            'lines' => $schema->integer()->description('How many recent lines, 1-200 (default 50)'),
        ];
    }

    public function command(array $arguments): string
    {
        $service = $arguments['service'] ?? null;
        $lines = $arguments['lines'] ?? 50;

        if (! is_string($service) || ! preg_match('/^[a-zA-Z0-9@._-]{1,64}$/', $service) || str_starts_with($service, '-')) {
            throw new InvalidToolArguments('Invalid service name.');
        }

        if (! is_int($lines) && ! (is_string($lines) && ctype_digit($lines))) {
            throw new InvalidToolArguments('lines must be an integer between 1 and 200.');
        }

        $lines = max(1, min(200, (int) $lines));

        return 'journalctl --no-pager -n '.$lines.' -u '.escapeshellarg($service).' 2>&1';
    }
}
