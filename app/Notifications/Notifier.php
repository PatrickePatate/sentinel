<?php

namespace App\Notifications;

use App\Ai\Severity;
use App\Models\AgentRun;
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

        $this->dispatch($run, fn () => NotificationChannel::where('enabled', true)->where('notify_scans', true)->get()
            ->filter(fn (NotificationChannel $c) => $severity->atLeast($c->minSeverity())),
            new ScanReportNotification($run, $severity));
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
