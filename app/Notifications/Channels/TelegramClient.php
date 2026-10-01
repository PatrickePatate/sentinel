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

    /**
     * Build a client from a bare token, before the channel exists.
     */
    public static function withToken(string $token): self
    {
        return new self(new NotificationChannel(['settings' => ['bot_token' => $token]]));
    }

    /**
     * The chat of the latest `/start <code>` message sent to the bot: only the person holding the code can claim the channel.
     *
     * @return array{chat_id: string, user_id: string, label: string}|null
     */
    public function latestChat(string $code): ?array
    {
        $updates = $this->call('getUpdates', ['timeout' => 0, 'allowed_updates' => ['message']]);

        foreach (array_reverse($updates) as $update) {
            $message = $update['message'] ?? null;

            if (isset($message['chat']['id'], $message['from']['id']) && preg_match('/^\/start(?:@\w+)?\s+'.preg_quote($code, '/').'$/', trim((string) ($message['text'] ?? '')))) {
                $chat = $message['chat'];

                return [
                    'chat_id' => (string) $chat['id'],
                    'user_id' => (string) $message['from']['id'],
                    'label' => (string) ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? $chat['id']),
                ];
            }
        }

        return null;
    }

    private function call(string $method, array $payload): array
    {
        $token = (string) $this->channel->setting('bot_token');

        $response = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException("Telegram {$method} failed: ".($response->json('description') ?? 'HTTP '.$response->status()));
        }

        // Some methods (setWebhook, answerCallbackQuery) answer `result: true`.
        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }
}
