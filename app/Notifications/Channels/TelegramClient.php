<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Minimal Telegram Bot API client. The bot token is in the URL: never let it reach logs or exception messages. */
class TelegramClient
{
    public function __construct(private NotificationChannel $channel) {}

    /** @param list<list<array{text: string, callback_data: string}>> $keyboard */
    public function sendMessage(string $html, array $keyboard = []): array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $this->channel->setting('chat_id'),
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $keyboard ? ['inline_keyboard' => $keyboard] : null,
        ]));
    }

    public function editMessage(int|string $chatId, int $messageId, string $html): void
    {
        // No reply_markup: the buttons are removed.
        $this->call('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $html, 'parse_mode' => 'HTML']);
    }

    public function answerCallback(string $callbackId, string $text): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 190), 'show_alert' => false]);
    }

    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['callback_query'], 'drop_pending_updates' => true]);
    }

    private function call(string $method, array $payload): array
    {
        $token = (string) $this->channel->setting('bot_token');

        $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException("Telegram {$method} failed: ".($response->json('description') ?? 'HTTP '.$response->status()));
        }

        return $response->json('result') ?? [];
    }
}
