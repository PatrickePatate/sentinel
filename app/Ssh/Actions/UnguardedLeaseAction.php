<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Opens or closes the time-boxed root lease behind an unguarded chat session (see UnguardedSession). */
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

    public function command(array $arguments): string
    {
        if ($arguments !== []) {
            throw new InvalidToolArguments("{$this->name()} takes no arguments.");
        }

        return $this->sudo().self::WRAPPER.($this->open ? ' on' : ' off').' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }
}
