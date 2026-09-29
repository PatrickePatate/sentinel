<?php

namespace App\Ssh\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Read-only: runs fail2ban-regex against an allowlisted log. Writes nothing.
 */
class Fail2banTestRegexTool implements Tool
{
    public function name(): string
    {
        return 'fail2ban_test_regex';
    }

    public function description(): string
    {
        return 'Test a fail2ban failregex against an allowlisted log file (read-only, shows matches and misses). Always test a regex with this before proposing a filter.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'log' => $schema->string()->description('Log key: '.implode(', ', array_keys(config('sentinel.fail2ban.logs'))))->required(),
            'failregex' => $schema->string()->description('A single failregex, must contain <HOST>')->required(),
        ];
    }

    public function command(array $arguments): string
    {
        $log = $arguments['log'] ?? null;
        $regex = $arguments['failregex'] ?? null;
        $logs = config('sentinel.fail2ban.logs');

        if (! is_string($log) || ! isset($logs[$log])) {
            throw new InvalidToolArguments('Unknown log key.');
        }

        self::assertValidRegex($regex);

        return 'timeout 15 fail2ban-regex -- '.escapeshellarg($logs[$log]).' '.escapeshellarg($regex).' 2>&1 | tail -n 40';
    }

    public static function assertValidRegex(mixed $regex): void
    {
        if (! is_string($regex) || $regex === '' || strlen($regex) > 500
            || ! preg_match('/^[\x21-\x2c\x2e-\x7e][\x20-\x7e]*$/', $regex)
            || str_contains($regex, '%(')
            || (! str_contains($regex, '<HOST>') && ! str_contains($regex, '<ADDR>'))) {
            throw new InvalidToolArguments('failregex must be 1-500 printable ASCII characters, not start with whitespace or "-", not contain "%(", and contain <HOST>.');
        }
    }
}
