<?php

namespace App\Agent;

use InvalidArgumentException;

class LlmManager
{
    public function driver(?string $name = null): LlmClient
    {
        $name ??= config('sentinel.llm.default');
        $config = config("sentinel.llm.providers.{$name}");

        return match ($config['driver'] ?? null) {
            'anthropic' => new AnthropicClient($config['api_key'], $config['model']),
            'openai' => new OpenAiCompatibleClient($config['base_url'], $config['model'], $config['api_key'] ?? null),
            default => throw new InvalidArgumentException("Unknown LLM provider: {$name}"),
        };
    }
}
