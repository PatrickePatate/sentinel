<?php

namespace App\Agent;

use Illuminate\Support\Facades\Http;

class AnthropicClient implements LlmClient
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.anthropic.com',
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function chat(string $system, array $messages, array $tools): LlmResponse
    {
        $data = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'])
            ->timeout(120)
            ->post("{$this->baseUrl}/v1/messages", [
                'model' => $this->model,
                'max_tokens' => 4096,
                'system' => $system,
                'tools' => $tools,
                'messages' => $this->toAnthropicMessages($messages),
            ])
            ->throw()
            ->json();

        $text = '';
        $calls = [];
        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $text .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $calls[] = new ToolCall($block['id'], $block['name'], (array) $block['input']);
            }
        }

        return new LlmResponse($text, $calls);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function toAnthropicMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'tool') {
                $block = ['type' => 'tool_result', 'tool_use_id' => $message['tool_call_id'], 'content' => $message['content']];
                $last = array_key_last($out);
                if ($last !== null && $out[$last]['role'] === 'user' && is_array($out[$last]['content'])) {
                    $out[$last]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }

            if ($message['role'] === 'assistant') {
                $blocks = [];
                if (($message['content'] ?? '') !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $message['content']];
                }
                foreach ($message['tool_calls'] ?? [] as $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => (object) $call['arguments']];
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            $out[] = ['role' => 'user', 'content' => $message['content']];
        }

        return $out;
    }
}
