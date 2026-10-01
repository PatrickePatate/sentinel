<?php

namespace App\Ssh\Tools;

use App\Ssh\Provisioning\RequiresSudo;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class WebConfigTestTool implements RequiresSudo, Tool
{
    public const WRAPPER = '/usr/local/sbin/sentinel-web-config';

    public const SERVICES = ['nginx', 'apache2', 'php-fpm'];

    public function name(): string
    {
        return 'web_config_test';
    }

    public function description(): string
    {
        return 'Run the web server\'s own configuration test (nginx -t, apache2ctl configtest, php-fpm -t). Read-only for the configuration: a passing test only records a "last known good" copy that rollback_web_config can restore.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['service' => $schema->string()->enum(self::SERVICES)->description('Which configuration to test')->required()];
    }

    public function command(array $arguments): string
    {
        $service = $arguments['service'] ?? null;

        if (! in_array($service, self::SERVICES, true)) {
            throw new InvalidToolArguments('Service must be one of: '.implode(', ', self::SERVICES).'.');
        }

        return (config('sentinel.actions.use_sudo') ? 'sudo -n ' : '').self::WRAPPER.' test '.escapeshellarg($service).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
