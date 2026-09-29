<?php

namespace App\Ssh\Gate;

final readonly class GateDecision
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public GateVerdict $verdict,
        public string $reason,
        public array $details = [],
    ) {}
}
