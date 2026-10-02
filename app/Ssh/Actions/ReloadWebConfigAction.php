<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use App\Ssh\Tools\WebConfigTestTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ReloadWebConfigAction implements ActionTool, HasSafeguards, RequiresSudo, Verifiable
{
    use UsesSudo;

    public function name(): string
    {
        return 'reload_web_config';
    }

    public function description(): string
    {
        return 'Reload nginx, apache2 or php-fpm only if their configuration passes the test (never restarts, so no downtime; a failing test changes nothing). Use it to apply a configuration change that is already on disk.';
    }

    public function safeguards(): string
    {
        return 'Enforced by the root wrapper: the configuration is tested first and nothing is reloaded unless the test passes; it only reloads (graceful, never a restart), so connections are not dropped; no file is modified.';
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

        return $this->sudo().WebConfigTestTool::WRAPPER.' reload '.escapeshellarg($service).' 2>&1';
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
            ['rollback_web_config', ['service' => $arguments['service']]],
        );
    }
}
