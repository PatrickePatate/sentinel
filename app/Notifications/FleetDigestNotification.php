<?php

namespace App\Notifications;

use App\Models\NotificationChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\TelegramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The weekly summary of the fleet (see FleetDigest). */
class FleetDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $digest */
    public function __construct(public array $digest) {}

    public function via(NotificationChannel $notifiable): array
    {
        return [$notifiable->type === 'telegram' ? TelegramChannel::class : 'mail'];
    }

    /** @return list<string> */
    private function overview(): array
    {
        $d = $this->digest;
        $open = collect(['critical', 'high', 'medium', 'low'])->filter(fn ($s) => ($d['open'][$s] ?? 0) > 0)->map(fn ($s) => "{$d['open'][$s]} {$s}")->implode(', ');

        return [
            'Open issues: '.($open ?: 'none'),
            "This week: {$d['opened']} new issue(s), {$d['resolved']} resolved, {$d['scans']} scan(s), {$d['actions']} action(s) executed".($d['cost'] > 0 ? sprintf(', about $%.2f of model usage', $d['cost']) : ''),
            "Waiting for approval: {$d['pending']}",
        ];
    }

    public function toMail(NotificationChannel $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('[Sentinel] Weekly digest')->lines($this->overview());

        foreach ($this->digest['machines'] as $machine) {
            $mail->line("**{$machine['name']}**: ".implode('; ', $machine['lines']));
        }

        if ($this->digest['machines'] === []) {
            $mail->line('Nothing needs attention on any machine.');
        }

        return $mail->line('Open Sentinel (Fleet and Issues pages) for the details.');
    }

    public function toTelegram(NotificationChannel $notifiable): TelegramMessage
    {
        $machines = collect($this->digest['machines'])->take(15)
            ->map(fn ($m) => '• <b>'.e($m['name']).'</b>: '.e(mb_substr(implode('; ', $m['lines']), 0, 300)))->implode("\n");

        return new TelegramMessage("📋 <b>Weekly digest</b>\n".collect($this->overview())->map(fn ($l) => e($l))->implode("\n")
            ."\n\n".($machines ?: 'Nothing needs attention on any machine.'));
    }
}
