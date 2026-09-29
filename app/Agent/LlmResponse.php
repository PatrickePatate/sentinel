<?php

namespace App\Agent;

final readonly class LlmResponse
{
    /** @param list<ToolCall> $toolCalls */
    public function __construct(
        public string $text,
        public array $toolCalls = [],
    ) {}
}
