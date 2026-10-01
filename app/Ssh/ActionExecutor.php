<?php

namespace App\Ssh;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Notifications\Notifier;
use App\Ssh\Actions\RiskLevel;
use App\Ssh\Gate\GateVerdict;
use App\Ssh\Gate\RiskGate;
use App\Ssh\Tools\InvalidToolArguments;
use App\Support\Realtime;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Only path for corrective actions. Every request goes through the RiskGate;
 * nothing changes on a machine without either an autonomous "execute" verdict
 * or an explicit human approval of a PendingAction.
 */
class ActionExecutor
{
    public function __construct(
        private ActionCatalog $catalog,
        private SshTransport $transport,
        private RiskGate $gate,
        private AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return string Message for the agent.
     */
    public function request(Machine $machine, string $actionName, array $arguments, ?AgentRun $run, string $objective): string
    {
        try {
            $action = $this->catalog->get($actionName);
            $command = $action->command($arguments);
        } catch (InvalidArgumentException|InvalidToolArguments $e) {
            $this->audit->record($machine, $run, 'rejected', $actionName, ['arguments' => $arguments, 'reason' => $e->getMessage()]);

            return 'ERROR: '.$e->getMessage();
        }

        $decision = $this->gate->assess($machine, $action, $command, $objective);
        $context = ['action' => $actionName, 'arguments' => $arguments, 'command' => $command, 'risk' => $action->risk()->value, 'reason' => $decision->reason, 'gate' => $decision->details];

        if ($decision->verdict === GateVerdict::Refuse) {
            $this->audit->record($machine, $run, 'action_refused', $actionName, $context);

            return "REFUSED: {$decision->reason} Do not retry; include it in your report.";
        }

        if ($decision->verdict === GateVerdict::Execute && $this->quotaReached($machine, $run)) {
            $decision = new Gate\GateDecision(GateVerdict::AskHuman, 'Autonomous action quota reached for this scan.', $decision->details);
            $context['reason'] = $decision->reason;
        }

        // Flapping: the same fix again and again means the cause is elsewhere, so stop doing it unattended and tell a human.
        if ($decision->verdict === GateVerdict::Execute && ($runs = $this->recentRuns($machine, $command)) >= config('sentinel.gate.flap_threshold')) {
            $decision = new Gate\GateDecision(GateVerdict::AskHuman, "Flapping: this exact action already ran {$runs} times in the last 24 hours, so the cause is probably elsewhere. A human should look at it.", $decision->details);
            $context['reason'] = $decision->reason;
        }

        if ($decision->verdict === GateVerdict::AskHuman) {
            $pending = PendingAction::create([
                'machine_id' => $machine->id,
                'agent_run_id' => $run?->id,
                'action' => $actionName,
                'arguments' => $arguments,
                'command' => $command,
                'risk' => $action->risk()->value,
                'reason' => $decision->reason,
            ]);
            $this->audit->record($machine, $run, 'action_pending', $actionName, $context + ['pending_action_id' => $pending->id]);
            app(Notifier::class)->approvalNeeded($pending);
            Realtime::push('actions');

            return "PENDING_HUMAN_APPROVAL (#{$pending->id}): {$decision->reason} Not executed; mention it in your report.";
        }

        return $this->run($machine, $run, $actionName, $command, $context, 'action_executed');
    }

    /**
     * Files a corrective action for human approval without ever running it, e.g. a fix the agent recommends
     * in its report. Same validation as request(); high-risk actions are still refused outright, and the model
     * cannot use a proposal to skip the gate: approving it goes through approve() like any held action.
     *
     * @param  array<string, mixed>  $arguments
     * @return string Message for the agent.
     */
    public function propose(Machine $machine, string $actionName, array $arguments, ?AgentRun $run, string $rationale): string
    {
        try {
            $action = $this->catalog->get($actionName);
            $command = $action->command($arguments);
        } catch (InvalidArgumentException|InvalidToolArguments $e) {
            $this->audit->record($machine, $run, 'rejected', $actionName, ['arguments' => $arguments, 'reason' => $e->getMessage()]);

            return 'ERROR: '.$e->getMessage();
        }

        $context = ['action' => $actionName, 'arguments' => $arguments, 'command' => $command, 'risk' => $action->risk()->value];

        if ($action->risk() === RiskLevel::High) {
            $this->audit->record($machine, $run, 'action_refused', $actionName, $context + ['reason' => 'High-risk actions cannot be proposed.']);

            return 'REFUSED: high-risk actions cannot be proposed. Describe the fix in your report for a human to apply by hand.';
        }

        // The same fix proposed twice (or by two scans) is one decision for the human.
        $existing = PendingAction::where('machine_id', $machine->id)->where('status', 'pending')->where('command', $command)->first();

        if ($existing) {
            return "ALREADY_PENDING (#{$existing->id}): this exact action is already waiting for approval.";
        }

        $pending = PendingAction::create([
            'machine_id' => $machine->id,
            'agent_run_id' => $run?->id,
            'action' => $actionName,
            'arguments' => $arguments,
            'command' => $command,
            'risk' => $action->risk()->value,
            'reason' => 'Proposed by the agent: '.(mb_substr(trim($rationale), 0, 500) ?: 'no rationale given'),
        ]);
        $this->audit->record($machine, $run, 'action_proposed', $actionName, $context + ['pending_action_id' => $pending->id]);
        app(Notifier::class)->approvalNeeded($pending);
        Realtime::push('actions');

        return "PROPOSED (#{$pending->id}): filed for human approval, not executed. List it under proposed fixes in your report.";
    }

    /**
     * Executes an action a human explicitly approved. The command is re-derived from the
     * catalog (never taken from the stored row) and must be identical to the one the human
     * reviewed; the pending -> running transition is atomic so it can only run once.
     */
    /** @param array<string, mixed> $context Extra audit properties (e.g. who approved, through which channel). */
    public function approve(PendingAction $pending, array $context = []): string
    {
        $this->claim($pending);

        $machine = $pending->machine;

        try {
            $command = $this->catalog->get($pending->action)->command($pending->arguments ?? []);
        } catch (InvalidArgumentException|InvalidToolArguments $e) {
            $pending->update(['status' => 'failed', 'output' => $e->getMessage(), 'decided_at' => now()]);

            return 'ERROR: '.$e->getMessage();
        }

        if ($command !== $pending->command) {
            $message = 'The command changed since it was reviewed (configuration updated). Ask the agent to request it again.';
            $pending->update(['status' => 'stale', 'output' => $message, 'decided_at' => now()]);
            $this->audit->record($machine, $pending->agentRun, 'action_stale', $pending->action, ['pending_action_id' => $pending->id, 'reviewed' => $pending->command, 'now' => $command]);

            return 'ERROR: '.$message;
        }

        $output = $this->run($machine, $pending->agentRun, $pending->action, $command, ['approved_pending_action_id' => $pending->id] + $context, 'action_approved');
        $pending->update(['status' => str_starts_with($output, 'ERROR') ? 'failed' : 'executed', 'output' => $output, 'decided_at' => now()]);
        Realtime::push('actions');

        return $output;
    }

    /** @param array<string, mixed> $context */
    public function reject(PendingAction $pending, array $context = []): void
    {
        $this->claim($pending, 'rejected');

        $this->audit->record($pending->machine, null, 'action_rejected', $pending->action, ['pending_action_id' => $pending->id] + $context);
        Realtime::push('actions');
    }

    /**
     * Atomically moves a pending action out of "pending": a double click or two admins
     * at once cannot both win. Expired requests are closed instead of run.
     */
    private function claim(PendingAction $pending, string $to = 'running'): void
    {
        $ttl = config('sentinel.gate.pending_ttl_hours');

        PendingAction::whereKey($pending->id)->where('status', 'pending')->where('created_at', '<', now()->subHours($ttl))
            ->update(['status' => 'expired', 'decided_at' => now()]);

        $claimed = PendingAction::whereKey($pending->id)->where('status', 'pending')
            ->update(['status' => $to, 'decided_at' => $to === 'rejected' ? now() : null]);

        abort_unless($claimed === 1, 409, 'Action already decided or expired.');

        $pending->refresh();
    }

    /** @param array<string, mixed> $context */
    private function run(Machine $machine, ?AgentRun $run, string $action, string $command, array $context, string $event): string
    {
        try {
            $result = $this->transport->run($machine, $command, SafeExecutor::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            $this->audit->record($machine, $run, 'action_failed', $action, $context + ['command' => $command, 'error' => $e->getMessage()]);

            return 'ERROR: the action could not be run on the machine (details are in the audit log).';
        }

        $output = mb_strcut(mb_convert_encoding($result->output, 'UTF-8', 'UTF-8'), 0, SafeExecutor::MAX_OUTPUT_BYTES);
        $this->audit->record($machine, $run, $event, $action, $context + ['command' => $command, 'exit_code' => $result->exitCode, 'output_excerpt' => $output]);

        return $output === '' ? "(no output, exit code {$result->exitCode})" : $output;
    }

    /** How many times this exact command ran on the machine in the last 24 hours (autonomously or approved). */
    public function recentRuns(Machine $machine, string $command): int
    {
        return Activity::query()
            ->where('log_name', 'ssh')->whereIn('event', ['action_executed', 'action_approved'])
            ->where('subject_type', $machine->getMorphClass())->where('subject_id', $machine->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->where('properties->command', $command)
            ->count();
    }

    private function quotaReached(Machine $machine, ?AgentRun $run): bool
    {
        if ($run === null) {
            return false;
        }

        return Activity::query()
            ->where('log_name', 'ssh')
            ->where('event', 'action_executed')
            ->where('properties->agent_run_id', $run->id)
            ->count() >= $machine->gate('max_actions');
    }
}
