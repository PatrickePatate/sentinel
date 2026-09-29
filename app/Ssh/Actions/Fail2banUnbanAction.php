<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class Fail2banUnbanAction implements ActionTool, RequiresSudo
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-fail2ban-unban';

    public function name(): string
    {
        return 'fail2ban_unban';
    }

    public function description(): string
    {
        return 'Remove an IP address from a fail2ban jail ban list (e.g. a legitimate user locked out). Needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'jail' => $schema->string()->description('fail2ban jail name, e.g. sshd')->required(),
            'ip' => $schema->string()->description('IPv4 or IPv6 address to unban')->required(),
        ];
    }

    public function command(array $arguments): string
    {
        $jail = $arguments['jail'] ?? null;
        $ip = $arguments['ip'] ?? null;

        if (! is_string($jail) || ! preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $jail)) {
            throw new InvalidToolArguments('Invalid jail name.');
        }

        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new InvalidToolArguments('Invalid IP address.');
        }

        return $this->sudo().self::WRAPPER.' '.escapeshellarg($jail).' '.escapeshellarg($ip).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
