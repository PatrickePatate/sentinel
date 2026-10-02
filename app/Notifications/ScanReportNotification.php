<?php

namespace App\Notifications;

use App\Ai\Severity;
use App\Models\AgentRun;
use App\Models\Finding;
use App\Models\NotificationChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\TelegramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScanReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AgentRun $run, public Severity $severity, public bool $urgent = false) {}

    public function via(NotificationChannel $notifiable): array
    {
        return [$notifiable->type === 'telegram' ? TelegramChannel::class : 'mail'];
    }

    private function headline(): string
    {
        $machine = $this->run->machine;
        $prefix = $this->urgent ? 'URGENT: ' : '';

        return $prefix.($this->run->status === 'failed'
            ? "Scan failed on {$machine->name}"
            : sprintf('%s finding on %s', ucfirst($this->severity->value), $machine->name));
    }

    private function summary(): string
    {
        return $this->run->status === 'failed'
            ? 'The scan could not complete (see the Scans menu for the reason).'
            : ($this->run->summary ?: mb_substr((string) $this->run->report, 0, 600));
    }

    /** One line per kind of change since the previous scan, e.g. "New: Password login enabled (high)". */
    private function changes(): array
    {
        $diff = $this->run->findings_diff ?? [];
        $findings = Finding::whereIn('id', collect($diff)->flatten()->all())->get()->keyBy('id');
        $lines = [];

        foreach (['new' => 'New', 'escalated' => 'Worse', 'resolved' => 'Resolved'] as $kind => $label) {
            $titles = collect($diff[$kind] ?? [])->map(fn ($id) => $findings->get($id))->filter()
                ->map(fn (Finding $f) => "{$f->title} ({$f->severity})");

            if ($titles->isNotEmpty()) {
                $lines[] = "{$label}: ".$titles->take(5)->implode('; ').($titles->count() > 5 ? ' and '.($titles->count() - 5).' more' : '');
            }
        }

        if ($ongoing = count($diff['ongoing'] ?? [])) {
            $lines[] = "Still open: {$ongoing}";
        }

        return $lines;
    }

    public function toMail(NotificationChannel $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Sentinel] '.$this->headline())
            ->line("Machine: {$this->run->machine->name} ({$this->run->machine->environment})")
            ->line('Severity: '.($this->run->status === 'failed' ? 'scan failed' : $this->severity->value).($this->run->severity ? '' : ' (no verdict given by the agent, assumed)'))
            ->line($this->summary())
            ->lines($this->changes())
            ->line("Scan #{$this->run->id} ({$this->run->trigger}). Open Sentinel to read the full report.");
    }

    public function toTelegram(NotificationChannel $notifiable): TelegramMessage
    {
        $icon = match (true) {
            $this->run->status === 'failed' => '❌',
            $this->severity->atLeast(Severity::High) => '🚨',
            default => '⚠️',
        };

        return new TelegramMessage(sprintf(
            "%s <b>%s</b>\n%s%s\n\n<i>Scan #%d (%s). Full report in Sentinel.</i>",
            $icon, e($this->headline()), e(mb_substr($this->summary(), 0, 1500)),
            collect($this->changes())->map(fn (string $line) => "\n".e(mb_substr($line, 0, 500)))->implode(''), $this->run->id, e($this->run->trigger),
        ));
    }
}
