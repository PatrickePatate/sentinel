<?php

namespace App\Agent;

use Illuminate\Support\Facades\Http;

/**
 * Works with OpenAI and any compatible server (Ollama, vLLM, LiteLLM...).
 */
class OpenAiCompatibleClient implements LlmClient
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private ?string $apiKey = null,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function chat(string $system, array $messages, array $tools): LlmResponse
    {
        $payload = [
            'model' => $this->model,
            'messages' => [['role' => 'system', 'content' => $system], ...$this->toOpenAiMessages($messages)],
            'tools' => array_map(fn (array $tool) => ['type' => 'function', 'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['input_schema'],
            ]], $tools),
        ];

        $request = Http::timeout(120);
        if ($this->apiKey) {
            $request = $request->withToken($this->apiKey);
        }

        $message = $request->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload)
            ->throw()
            ->json('choices.0.message');

        $calls = array_map(fn (array $call) => new ToolCall(
            $call['id'],
            $call['function']['name'],
            (array) json_decode($call['function']['arguments'] ?: '{}', true),
        ), $message['tool_calls'] ?? []);

        return new LlmResponse((string) ($message['content'] ?? ''), $calls);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function toOpenAiMessages(array $messages): array
    {
        return array_map(function (array $message) {
            if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
                return [
                    'role' => 'assistant',
                    'content' => $message['content'] ?: null,
                    'tool_calls' => array_map(fn (array $call) => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => ['name' => $call['name'], 'arguments' => json_encode((object) $call['arguments'])],
                    ], $message['tool_calls']),
                ];
            }

            return $message;
        }, $messages);
    }
}
