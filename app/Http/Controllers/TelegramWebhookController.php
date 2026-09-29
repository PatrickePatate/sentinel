<?php

namespace App\Http\Controllers;

use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Notifications\Channels\TelegramClient;
use App\Ssh\ActionExecutor;
use App\Ssh\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Receives the Approve / Reject button presses. Three gates before anything runs: the URL secret header set with
 * setWebhook, the channel's allowed approver ids, and the same atomic claim + stale-command check as the back-office.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, NotificationChannel $channel, ActionExecutor $executor, AuditTrail $audit): Response
    {
        $secret = (string) $channel->setting('webhook_secret');

        abort_unless(
            $channel->enabled && $channel->type === 'telegram' && $secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            404,
        );

        $callback = $request->input('callback_query');

        // Always answer 200 quickly: Telegram retries anything else.
        if (! is_array($callback) || ! preg_match('/^(approve|reject):(\d+)$/', (string) ($callback['data'] ?? ''), $m)) {
            return response()->noContent();
        }

        $client = new TelegramClient($channel);
        $userId = (string) ($callback['from']['id'] ?? '');
        $chatId = $callback['message']['chat']['id'] ?? null;
        $messageId = $callback['message']['message_id'] ?? null;
        $pending = PendingAction::with('machine')->find((int) $m[2]);

        try {
            $allowed = $userId !== '' && in_array($userId, $channel->approverIds(), true) && (string) $chatId === (string) $channel->setting('chat_id');

            if (! $pending || ! $allowed) {
                if ($pending) {
                    $audit->record($pending->machine, $pending->agentRun, 'action_approval_denied', $pending->action, ['pending_action_id' => $pending->id, 'via' => 'telegram', 'telegram_user_id' => $userId]);
                }

                $client->answerCallback($callback['id'], $pending ? 'You are not allowed to decide this.' : 'Unknown action.');

                return response()->noContent();
            }

            $context = ['via' => 'telegram', 'telegram_user_id' => $userId, 'channel_id' => $channel->id];
            $who = e($callback['from']['username'] ?? $userId);

            if ($m[1] === 'reject') {
                $executor->reject($pending, $context);
                $result = "✖ <b>Rejected</b> by {$who}";
            } else {
                $output = $executor->approve($pending, $context);
                $ok = ! str_starts_with($output, 'ERROR');
                $result = ($ok ? '✅ <b>Approved and run</b>' : '⚠️ <b>Approved but failed</b>')." by {$who}\n<pre>".e(mb_substr($output, 0, 1500)).'</pre>';
            }

            $client->answerCallback($callback['id'], 'Done.');
            $this->closeMessage($client, $chatId, $messageId, $pending, $result);
        } catch (HttpException) {
            $client->answerCallback($callback['id'], 'Already decided or expired.');
            $this->closeMessage($client, $chatId, $messageId, $pending, '⌛ Already decided or expired.');
        } catch (Throwable $e) {
            report($e);
        }

        return response()->noContent();
    }

    private function closeMessage(TelegramClient $client, mixed $chatId, mixed $messageId, ?PendingAction $pending, string $result): void
    {
        if (! $pending || ! $chatId || ! $messageId) {
            return;
        }

        try {
            $client->editMessage($chatId, (int) $messageId, sprintf("🛡️ Action <code>%s</code> on <b>%s</b> (#%d)\n%s", e($pending->action), e($pending->machine->name), $pending->id, $result));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
