<?php

namespace App\Ssh\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class WebErrorLogsTool implements Tool
{
    /** Log files per component (the SSH account is in the adm group, which can read them on Debian and Ubuntu). */
    public const LOGS = [
        'nginx' => '/var/log/nginx/error.log',
        'apache2' => '/var/log/apache2/error.log',
        'php-fpm' => '/var/log/php*-fpm.log',
        'mysql' => '/var/log/mysql/error.log',
        'postgresql' => '/var/log/postgresql/postgresql-*.log',
        'redis' => '/var/log/redis/redis-server.log',
    ];

    public function name(): string
    {
        return 'web_error_logs';
    }

    public function description(): string
    {
        return 'Last lines of the error log of a web stack component (read-only): nginx, apache2, php-fpm, mysql, postgresql or redis.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'component' => $schema->string()->enum(array_keys(self::LOGS))->description('Which error log')->required(),
            'lines' => $schema->integer()->description('How many recent lines, 1-200 (default 40)'),
        ];
    }

    public function command(array $arguments): string
    {
        $component = $arguments['component'] ?? null;
        $lines = $arguments['lines'] ?? 40;

        if (! is_string($component) || ! isset(self::LOGS[$component])) {
            throw new InvalidToolArguments('Component must be one of: '.implode(', ', array_keys(self::LOGS)).'.');
        }

        if (! is_int($lines) && ! (is_string($lines) && ctype_digit($lines))) {
            throw new InvalidToolArguments('lines must be an integer between 1 and 200.');
        }

        $path = self::LOGS[$component];
        $lines = max(1, min(200, (int) $lines));

        // The glob is expanded by the shell, never from input: one -n tail per file would need a loop, so take the newest file.
        return "f=\$(ls -t {$path} 2>/dev/null | head -n 1); if [ -n \"\$f\" ]; then echo \"== \$f\"; tail -n {$lines} \"\$f\" 2>&1; else echo \"no {$component} error log found (the component may not be installed, or logs to the journal: try service_logs)\"; fi";
    }
}
