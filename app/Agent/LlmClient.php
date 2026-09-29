<?php

namespace App\Agent;

interface LlmClient
{
    public function name(): string;

    /**
     * Neutral message format:
     * - ['role' => 'user'|'assistant', 'content' => string, 'tool_calls' => list<array{id, name, arguments}>?]
     * - ['role' => 'tool', 'tool_call_id' => string, 'content' => string]
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     */
    public function chat(string $system, array $messages, array $tools): LlmResponse;
}
