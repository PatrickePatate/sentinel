<?php

namespace App\Ssh\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Requests a page from the machine itself (loopback only), to see what the web server really answers. */
class HttpProbeTool implements Tool
{
    public function name(): string
    {
        return 'http_probe';
    }

    public function description(): string
    {
        return 'Request a page from the machine itself (127.0.0.1 only) and report the HTTP status and response time. Use the host argument to hit a specific virtual host.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'host' => $schema->string()->description('Host header / virtual host name, e.g. example.org (default: none)'),
            'path' => $schema->string()->description('Path starting with /, default /'),
            'https' => $schema->boolean()->description('Use HTTPS on port 443 (certificate not verified) instead of HTTP on port 80'),
        ];
    }

    public function command(array $arguments): string
    {
        $host = $arguments['host'] ?? null;
        $path = $arguments['path'] ?? '/';
        $https = (bool) ($arguments['https'] ?? false);

        if ($host !== null && $host !== '' && (! is_string($host) || ! preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $host))) {
            throw new InvalidToolArguments('Invalid host name.');
        }

        if (! is_string($path) || ! preg_match('#^/[A-Za-z0-9._~/%+=,:@-]{0,200}(\?[A-Za-z0-9._~/%+=,:@&-]{0,200})?$#', $path)) {
            throw new InvalidToolArguments('Path must start with / and contain only URL-safe characters.');
        }

        $url = ($https ? 'https://127.0.0.1' : 'http://127.0.0.1').$path;
        $header = $host ? ' -H '.escapeshellarg("Host: {$host}") : '';

        return 'curl -sS -k -m 8 -o /dev/null'.$header.' -w "HTTP %{http_code} in %{time_total}s (redirect: %{redirect_url})\n" '.escapeshellarg($url).' 2>&1';
    }
}
