<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\Fail2banTestRegexTool;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class Fail2banWriteFilterAction implements ActionTool, RequiresSudo
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-fail2ban-filter';

    public function name(): string
    {
        return 'fail2ban_write_filter';
    }

    public function description(): string
    {
        return 'Create or replace a custom fail2ban filter (filter.d/sentinel-<name>.conf) made only of failregex lines (max 5, one per line, each with <HOST>). The file is syntax-checked before install. Test regexes with fail2ban_test_regex first. Needs human approval.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Lowercase slug, e.g. nginx-wp-login')->required(),
            'failregex' => $schema->string()->description('1 to 5 failregex lines separated by newlines')->required(),
        ];
    }

    public function command(array $arguments): string
    {
        $name = $arguments['name'] ?? null;
        $failregex = $arguments['failregex'] ?? null;

        if (! is_string($name) || ! preg_match('/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/', $name)) {
            throw new InvalidToolArguments('Invalid filter name.');
        }

        $lines = is_string($failregex) ? explode("\n", trim(str_replace("\r", '', $failregex))) : [];

        if ($lines === [] || count($lines) > 5) {
            throw new InvalidToolArguments('Provide between 1 and 5 failregex lines.');
        }

        foreach ($lines as $line) {
            Fail2banTestRegexTool::assertValidRegex($line);
        }

        // Base64 keeps the payload inert through the shell; the wrapper decodes and re-validates it.
        return 'printf %s '.escapeshellarg(base64_encode(implode("\n", $lines))).' | '.$this->sudo().self::WRAPPER.' '.escapeshellarg($name).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
