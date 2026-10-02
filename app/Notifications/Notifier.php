<?php

namespace App\Notifications;

use App\Ai\Severity;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\NotificationChannel;
use App\Models\PendingAction;
use App\Ssh\AuditTrail;
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
        // A scan with no verdict is treated as medium: fail towards telling someone, never towards silence.
        $severity = $failed ? Severity::High : (Severity::tryFrom((string) $run->severity) ?? Severity::Medium);

        // A scheduled web server check that leaves a component down reaches every scan channel, whatever its minimum severity.
        $urgent = $run->profile === 'webserver' && in_array($run->trigger, ['scheduled', 'site_down'], true) && $severity->atLeast(Severity::High);

        if (! $failed && ! $urgent && $this->nothingChanged($run, $severity)) {
            return;
        }

        $this->dispatch($run, fn () => NotificationChannel::where('enabled', true)->where('notify_scans', true)->get()
            ->filter(fn (NotificationChannel $c) => $urgent || $severity->atLeast($c->minSeverity())),
            new ScanReportNotification($run, $severity, $urgent));
    }

    /**
     * A scan that only confirms what earlier scans already reported is not worth a message: nothing new, nothing worse.
     * High and critical verdicts are always sent, so an ongoing serious problem keeps being reported.
     */
    private function nothingChanged(AgentRun $run, Severity $severity): bool
    {
        $diff = $run->findings_diff;

        return config('sentinel.notifications.only_changes', true)
            && is_array($diff) && $diff['ongoing'] !== []
            && $diff['new'] === [] && $diff['escalated'] === []
            && ! $severity->atLeast(Severity::High);
    }

    /** @param array<string, mixed> $digest The weekly summary, for every channel that takes scan notifications. */
    public function digest(array $digest): void
    {
        $this->dispatch(null, fn () => NotificationChannel::where('enabled', true)->where('notify_scans', true)->get(), new FleetDigestNotification($digest));
    }

    /** Tells every channel that takes scan notifications. */
    public function machineAlert(Machine $machine, string $title, string $body, bool $urgent = true): void
    {
        $this->dispatch(null, fn () => NotificationChannel::where('enabled', true)->where('notify_scans', true)->get(), new MachineAlertNotification($machine, $title, $body, $urgent), $machine);
    }

    public function approvalNeeded(PendingAction $pending): void
    {
        $this->dispatch($pending->agentRun, fn () => NotificationChannel::where('enabled', true)->where('notify_approvals', true)->get(),
            new ActionApprovalNotification($pending), $pending->machine);
    }

    private function dispatch(?AgentRun $run, callable $channels, $notification, mixed $machine = null): void
    {
        $machine ??= $run?->machine;

        try {
            foreach ($channels() as $channel) {
                try {
                    $channel->notify($notification);
                } catch (Throwable $e) {
                    $this->failed($machine, $run, $channel, $e);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function failed($machine, ?AgentRun $run, NotificationChannel $channel, Throwable $e): void
    {
        report($e);

        if ($machine) {
            $this->audit->record($machine, $run, 'notification_failed', $channel->name, ['channel_id' => $channel->id, 'type' => $channel->type, 'error' => $e->getMessage()]);
        }
    }
}
