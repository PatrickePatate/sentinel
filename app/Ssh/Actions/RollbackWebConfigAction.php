<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use App\Ssh\Tools\WebConfigTestTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class RollbackWebConfigAction implements ActionTool, HasSafeguards, RequiresSudo, Verifiable
{
    use UsesSudo;

    public function name(): string
    {
        return 'rollback_web_config';
    }

    public function description(): string
    {
        return 'When the CURRENT nginx, apache2 or php-fpm configuration fails its test, restore the last configuration that passed it (recorded by earlier tests and reloads) and reload. The broken configuration is kept next to it with a .sentinel-broken suffix. Does nothing if the current configuration is valid.';
    }

    public function safeguards(): string
    {
        return 'Enforced by the root wrapper: it does nothing when the current configuration passes its own test; it only acts on a configuration that is already broken (the service cannot load it anyway); the broken configuration is kept next to the restored one (nothing is deleted) and can be put back; the restored copy must pass the service own test before it is used, otherwise the original is put back untouched.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Low;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['service' => $schema->string()->enum(WebConfigTestTool::SERVICES)->description('Which configuration')->required()];
    }

    public function command(array $arguments): string
    {
        $service = $arguments['service'] ?? null;

        if (! in_array($service, WebConfigTestTool::SERVICES, true)) {
            throw new InvalidToolArguments('Service must be one of: '.implode(', ', WebConfigTestTool::SERVICES).'.');
        }

        return $this->sudo().WebConfigTestTool::WRAPPER.' rollback '.escapeshellarg($service).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [WebConfigTestTool::WRAPPER.' *'];
    }

    public function verification(array $arguments): ?Verification
    {
        return new Verification(
            $this->sudo().WebConfigTestTool::WRAPPER.' test '.escapeshellarg($arguments['service']).' 2>&1',
            fn ($r) => $r->exitCode === 0,
            "the {$arguments['service']} configuration passes its test",
            null,
        );
    }
}
