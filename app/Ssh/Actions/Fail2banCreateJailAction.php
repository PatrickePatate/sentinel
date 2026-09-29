<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class Fail2banCreateJailAction implements ActionTool, RequiresSudo
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-fail2ban-jail';

    public function name(): string
    {
        return 'fail2ban_create_jail';
    }

    public function description(): string
    {
        return 'Create or replace a custom fail2ban jail (jail.d/sentinel-<name>.local) that uses a filter previously written with fail2ban_write_filter, on an allowlisted log. Only maxretry/findtime/bantime are configurable: no custom action, no command. The config is tested before reload and rolled back on failure. Needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Jail slug')->required(),
            'filter' => $schema->string()->description('Slug of a filter created with fail2ban_write_filter')->required(),
            'log' => $schema->string()->description('Log key: '.implode(', ', array_keys(config('sentinel.fail2ban.logs'))))->required(),
            'maxretry' => $schema->integer()->min(3)->max(20)->required(),
            'findtime' => $schema->integer()->min(60)->max(86400)->description('Seconds')->required(),
            'bantime' => $schema->integer()->min(60)->max(604800)->description('Seconds')->required(),
        ];
    }

    public function command(array $arguments): string
    {
        $slug = '/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/';

        foreach (['name', 'filter'] as $key) {
            if (! is_string($arguments[$key] ?? null) || ! preg_match($slug, $arguments[$key])) {
                throw new InvalidToolArguments("Invalid {$key}.");
            }
        }

        if (! is_string($arguments['log'] ?? null) || ! isset(config('sentinel.fail2ban.logs')[$arguments['log']])) {
            throw new InvalidToolArguments('Unknown log key.');
        }

        foreach (['maxretry' => [3, 20], 'findtime' => [60, 86400], 'bantime' => [60, 604800]] as $key => [$min, $max]) {
            $value = $arguments[$key] ?? null;

            if (! is_int($value) || $value < $min || $value > $max) {
                throw new InvalidToolArguments("{$key} must be an integer between {$min} and {$max}.");
            }
        }

        return $this->sudo().self::WRAPPER.' '.implode(' ', array_map('escapeshellarg', [
            $arguments['name'], $arguments['filter'], $arguments['log'],
            (string) $arguments['maxretry'], (string) $arguments['findtime'], (string) $arguments['bantime'],
        ])).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
