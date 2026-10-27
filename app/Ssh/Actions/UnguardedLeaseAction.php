<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Opens or closes the time-boxed lease behind an unguarded chat session (see UnguardedSession). The same wrapper runs
 * the approved commands, each with its own signed token: see signedCommand().
 */
class UnguardedLeaseAction implements ActionTool, HumanOnly, RequiresSudo
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-unguarded';

    public function __construct(private bool $open) {}

    public function name(): string
    {
        return $this->open ? 'unguarded_open' : 'unguarded_close';
    }

    public function description(): string
    {
        return $this->open
            ? 'Open a time-boxed root lease for an unguarded chat session. Started by a human only.'
            : 'Close the root lease of an unguarded chat session. Started by a human only.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::High;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /** Opening takes the signed lease token (see UnguardedSession::open), both base64; closing takes nothing. */
    public function command(array $arguments): string
    {
        if (! $this->open) {
            if ($arguments !== []) {
                throw new InvalidToolArguments('unguarded_close takes no arguments.');
            }

            return $this->sudo().self::WRAPPER.' off 2>&1';
        }

        $token = $arguments['token'] ?? null;
        $signature = $arguments['signature'] ?? null;

        if (array_keys($arguments) !== ['token', 'signature'] || ! is_string($token) || ! is_string($signature)
            || ! preg_match('#^[A-Za-z0-9+/=]{1,1024}$#', $token) || ! preg_match('#^[A-Za-z0-9+/=]{1,4096}$#', $signature)) {
            throw new InvalidToolArguments('unguarded_open takes a base64 token and signature.');
        }

        return $this->signedCommand('on', $token, $signature);
    }

    /** The wrapper call for a signed token: 'on' opens the lease, 'run' runs the one command its token carries. */
    public function signedCommand(string $verb, string $token, string $signature): string
    {
        return $this->sudo().self::WRAPPER.' '.$verb.' '.escapeshellarg($token).' '.escapeshellarg($signature).' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
