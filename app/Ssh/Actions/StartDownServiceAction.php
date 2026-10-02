<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class StartDownServiceAction implements ActionTool, HasSafeguards, RequiresSudo, Verifiable
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-service-recover';

    public function name(): string
    {
        return 'start_crashed_service';
    }

    public function description(): string
    {
        return 'Start a service that is DOWN (failed or inactive): resets its failed state and starts it. It does nothing to a running service, '
            .'and refuses to start nginx, apache2 or php-fpm when their configuration test fails (use rollback_web_config for that). '
            .'Only web stack units (web servers, php-fpm, databases, caches, queues).';
    }

    public function safeguards(): string
    {
        return 'Enforced by the root wrapper, not by the caller: it only starts a unit that is already failed or inactive and does nothing at all to a running one (no restart, no downtime); only web stack units from a fixed allowlist; a web server whose configuration test fails is not started; nothing is deleted or modified, the unit is merely started.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['service' => $schema->string()->description('Unit name, e.g. nginx, mysql, php8.3-fpm, redis-server')->required()];
    }

    public function command(array $arguments): string
    {
        $service = $arguments['service'] ?? null;

        // The root wrapper holds the real allowlist; this only keeps odd input out of the command line.
        if (! is_string($service) || ! preg_match('/^[a-zA-Z0-9@._-]{1,64}$/', $service) || str_starts_with($service, '-')) {
            throw new InvalidToolArguments('Invalid service name.');
        }

        return $this->sudo().self::WRAPPER.' '.escapeshellarg($service).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }

    public function verification(array $arguments): ?Verification
    {
        return Verification::unitActive($arguments['service']);
    }
}
