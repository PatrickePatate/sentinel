<?php

namespace App\Notifications;

use App\Ai\Severity;
use App\Models\AgentRun;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Ssh\AuditTrail;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Decides who is told what. Never throws: a broken channel must not break a scan or an approval flow.
 */
class Notifier
{
    public function __construct(private AuditTrail $audit) {}

    public function scanFinished(AgentRun $run): void
    {
        $failed = $run->status === 'failed';
        // A completed scan with a diff is judged by what is news; a failed or diff-less one keeps the verdict rule.
        $byChange = ! $failed && config('sentinel.notifications.only_changes', true) && is_array($run->findings_diff);
        [$news, $reminders] = $byChange ? $this->newsAndReminders($run) : [collect(), collect()];

        if ($byChange && $news->isEmpty() && $reminders->isEmpty()) {
            return;
        }

        // A scan with no verdict is treated as medium: fail towards telling someone, never towards silence.
        $severity = match (true) {
            $failed => Severity::High,
            $byChange => $news->concat($reminders)->reduce(fn (Severity $worst, Finding $f) => $f->severityLevel()->rank() > $worst->rank() ? $f->severityLevel() : $worst, Severity::None),
            default => Severity::tryFrom((string) $run->severity) ?? Severity::Medium,
        };

        // A scheduled web server check that leaves a component down reaches every scan channel, whatever its minimum severity.
        $urgent = $run->profile === 'webserver' && in_array($run->trigger, ['scheduled', 'site_down', 'escalation'], true) && $severity->atLeast(Severity::High);

        if ($this->quiet($run->machine, 'scan report', $run)) {
            return;
        }

        $channels = NotificationChannel::where('enabled', true)->where('notify_scans', true)->get()
            ->filter(fn (NotificationChannel $c) => $urgent || $severity->atLeast($c->minSeverity()));
        $ids = fn (Collection $findings): array => $findings->pluck('id')->map(fn ($id) => (int) $id)->all();
        $notification = new ScanReportNotification($run, $severity, $urgent, $byChange ? $ids($news) : null, $ids($reminders));

        $sent = $this->dispatch($run, fn () => $channels, $notification);

        // Nobody was reached: the findings stay unannounced, so the next scan tries again.
        if ($sent === 0) {
            return;
        }

        $findings = $news->concat($reminders);
        // Why it was sent, most urgent first: a new problem beats a worse one, which beats a reminder.
        $reason = match (true) {
            $failed => 'failed',
            ! $byChange => 'verdict',
            $news->contains(fn (Finding $f) => $f->notifiedLevel() === null) => 'new',
            $news->isNotEmpty() => 'worse',
            default => 'reminder',
        };

        $findings->each(fn (Finding $f) => $f->update(['notified_severity' => $f->severity, 'last_notified_at' => now()]));
        $this->audit->record($run->machine, $run, 'notification_sent', $notification->headline(), ['reason' => $reason, 'finding_ids' => $ids($findings), 'channels' => $sent]);
    }

    /**
     * What this scan has to tell: findings nobody was told about, or told about at a lower severity (news), and serious
     * open ones last announced long ago (reminders). Acknowledged, accepted and muted findings never qualify.
     *
     * @return array{0: Collection<int, Finding>, 1: Collection<int, Finding>}
     */
    private function newsAndReminders(AgentRun $run): array
    {
        $open = Finding::where('machine_id', $run->machine_id)->where('profile', $run->profile ?? 'audit')
            ->where('last_seen_run_id', $run->id)->get()->filter(fn (Finding $f) => $f->needsAttention());

        // Worse than what was already announced: a severity that went down and back up to the same level is not news.
        $news = $open->filter(fn (Finding $f) => $f->notifiedLevel() === null || $f->severityLevel()->rank() > $f->notifiedLevel()->rank())->values();

        $days = (int) config('sentinel.notifications.remind_days', 14);
        $reminders = $days > 0 ? $open->reject(fn (Finding $f) => $news->contains('id', $f->id))
            ->filter(fn (Finding $f) => $f->severityLevel()->atLeast(Severity::High) && $f->last_notified_at?->lt(now()->subDays($days)))
            ->values() : collect();

        return [$news, $reminders];
    }

    /** Planned work or a maintenance window: the message is not sent, only logged. Approval requests are never muted. */
    private function quiet(Machine $machine, string $what, ?AgentRun $run = null): bool
    {
        if (! $machine->isQuiet()) {
            return false;
        }

        $this->audit->record($machine, $run, 'notification_suppressed', $what, ['reason' => $machine->underPlannedWork() ? 'planned work' : 'maintenance window']);

        return true;
    }

    /** @param array<string, mixed> $digest The weekly summary, for every channel that takes scan notifications. */
    public function digest(array $digest): void
    {
        $this->dispatch(null, fn () => NotificationChannel::where('enabled', true)->where('notify_scans', true)->get(), new FleetDigestNotification($digest));
    }

    /** Tells every channel that takes scan notifications. */
    /** @param bool $evenWhenQuiet For news a maintenance window must not hide, e.g. an action that ran in it and did not work. */
    public function machineAlert(Machine $machine, string $title, string $body, bool $urgent = true, bool $evenWhenQuiet = false): void
    {
        if (! $evenWhenQuiet && $this->quiet($machine, $title)) {
            return;
        }

        $sent = $this->dispatch(null, fn () => NotificationChannel::where('enabled', true)->where('notify_scans', true)->get(), new MachineAlertNotification($machine, $title, $body, $urgent), $machine);

        if ($sent > 0) {
            $this->audit->record($machine, null, 'notification_sent', $title, ['reason' => 'alert', 'channels' => $sent]);
        }
    }

    public function approvalNeeded(PendingAction $pending): void
    {
        $this->dispatch($pending->agentRun, fn () => NotificationChannel::where('enabled', true)->where('notify_approvals', true)->get(),
            new ActionApprovalNotification($pending), $pending->machine);
    }

    /** @return int How many channels accepted the notification. */
    private function dispatch(?AgentRun $run, callable $channels, $notification, mixed $machine = null): int
    {
        $machine ??= $run?->machine;
        $sent = 0;

        try {
            foreach ($channels() as $channel) {
                try {
                    $channel->notify($notification);
                    $sent++;
                } catch (Throwable $e) {
                    $this->failed($machine, $run, $channel, $e);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $sent;
    }

    private function failed($machine, ?AgentRun $run, NotificationChannel $channel, Throwable $e): void
    {
        report($e);

        if ($machine) {
            $this->audit->record($machine, $run, 'notification_failed', $channel->name, ['channel_id' => $channel->id, 'type' => $channel->type, 'error' => $e->getMessage()]);
        }
    }
}
