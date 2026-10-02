<?php

namespace App\Ssh;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Notifications\Notifier;
use App\Ssh\Actions\HasSafeguards;
use App\Ssh\Actions\RiskLevel;
use App\Ssh\Actions\Verifiable;
use App\Ssh\Gate\GateVerdict;
use App\Ssh\Gate\RiskGate;
use App\Ssh\Tools\InvalidToolArguments;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
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

        $decision = $this->gate->assess($machine, $action, $command, $objective, $run);
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
        $existing = PendingAction::where('machine_id', $machine->id)->whereIn('status', ['pending', 'scheduled'])->where('command', $command)->first();

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
            // The rationale is model text, possibly steered by what an attacker wrote into the logs the agent read.
            'reason' => 'Proposed by the agent (its own words, not verified: it may repeat text planted in the logs it read): '
                .(mb_substr(trim($rationale), 0, 500) ?: 'no rationale given')
                .($actionName === 'fail2ban_unban' ? ' Unbanning lets this address connect again: make sure it is yours before approving.' : ''),
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
    public function approve(PendingAction $pending, array $context = [], string $from = 'pending'): string
    {
        // A scheduled action already collected its approvals when it was scheduled.
        if ($from === 'pending' && ($waiting = $this->awaitSecondApproval($pending, $context)) !== null) {
            return $waiting;
        }

        $this->claim($pending, 'running', $from);

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

        $output = $this->run($machine, $pending->agentRun, $pending->action, $command, ['approved_pending_action_id' => $pending->id, 'arguments' => $pending->arguments ?? []] + $context, 'action_approved');
        $pending->update(['status' => str_starts_with($output, 'ERROR') || str_contains($output, "\nVERIFICATION FAILED") ? 'failed' : 'executed', 'output' => $output, 'decided_at' => now()]);
        Realtime::push('actions');

        return $output;
    }

    /**
     * Approves an action to run at the start of the machine's next maintenance window instead of now. When it runs it
     * goes through approve(): the command is derived again and must still be the one that was reviewed.
     *
     * @param  array<string, mixed>  $context
     */
    public function schedule(PendingAction $pending, array $context = []): ?string
    {
        $window = $pending->machine->maintenanceWindow();
        abort_unless($window !== null, 422, 'This machine has no maintenance window.');

        if (($waiting = $this->awaitSecondApproval($pending, $context)) !== null) {
            return $waiting;
        }

        $this->claim($pending, 'scheduled');
        $pending->update(['run_after' => $window[0]]);
        $this->audit->record($pending->machine, $pending->agentRun, 'action_scheduled', $pending->action, ['pending_action_id' => $pending->id, 'run_after' => $window[0]->toIso8601String()] + $context);
        Realtime::push('actions');

        return null;
    }

    /**
     * On a machine that requires two people, the first approval is only recorded: null means "go ahead", otherwise the
     * message says what is still missing. Both must be dashboard users, and different ones: a Telegram or command line
     * approval is not tied to a Sentinel account, so the same person could count twice.
     *
     * @param  array<string, mixed>  $context
     */
    private function awaitSecondApproval(PendingAction $pending, array $context): ?string
    {
        if (! $pending->machine->two_person_approval) {
            return null;
        }

        $user = auth()->user();

        if (! $user || isset($context['via'])) {
            $this->audit->record($pending->machine, $pending->agentRun, 'action_approval_denied', $pending->action, ['pending_action_id' => $pending->id, 'reason' => 'two-person approval needs dashboard users'] + $context);

            return 'ERROR: this machine needs two approvals from different people in the dashboard. Approve it there.';
        }

        return DB::transaction(function () use ($pending, $user) {
            $approvals = PendingAction::whereKey($pending->id)->lockForUpdate()->value('approvals');
            $approvals = is_string($approvals) ? json_decode($approvals, true) : ($approvals ?? []);

            if (collect($approvals)->contains('user_id', $user->id)) {
                return 'WAITING: you already approved it; a second person has to approve it too.';
            }

            if ($approvals !== []) {
                return null;
            }

            $pending->update(['approvals' => [['user_id' => $user->id, 'name' => $user->name, 'at' => now()->toIso8601String()]]]);
            $this->audit->record($pending->machine, $pending->agentRun, 'action_first_approval', $pending->action, ['pending_action_id' => $pending->id]);
            Realtime::push('actions');

            return 'WAITING: first approval recorded; a second person has to approve it before it runs.';
        });
    }

    /** Runs the scheduled actions whose window has come. One whose window was missed (scheduler down) waits for the next one. */
    public function runScheduled(): int
    {
        $ran = 0;

        foreach (PendingAction::with('machine')->where('status', 'scheduled')->where('run_after', '<=', now())->get() as $pending) {
            if (! $pending->machine->inMaintenanceWindow()) {
                $pending->update(['run_after' => $pending->machine->maintenanceWindow()[0] ?? now()->addDay()]);

                continue;
            }

            try {
                $this->approve($pending, ['scheduled' => true], from: 'scheduled');
                $ran++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $ran;
    }

    /** @param array<string, mixed> $context */
    public function reject(PendingAction $pending, array $context = []): void
    {
        $this->claim($pending, 'rejected', $pending->status === 'scheduled' ? 'scheduled' : 'pending');

        $this->audit->record($pending->machine, null, 'action_rejected', $pending->action, ['pending_action_id' => $pending->id] + $context);
        Realtime::push('actions');
    }

    /**
     * Atomically moves a pending action out of "pending": a double click or two admins
     * at once cannot both win. Expired requests are closed instead of run.
     */
    private function claim(PendingAction $pending, string $to = 'running', string $from = 'pending'): void
    {
        $ttl = config('sentinel.gate.pending_ttl_hours');

        // A request nobody answered expires; one a human approved for the window does not.
        PendingAction::whereKey($pending->id)->where('status', 'pending')->where('created_at', '<', now()->subHours($ttl))
            ->update(['status' => 'expired', 'decided_at' => now()]);

        $claimed = PendingAction::whereKey($pending->id)->where('status', $from)
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
        $output = $output === '' ? "(no output, exit code {$result->exitCode})" : $output;

        return $output.$this->verify($machine, $run, $action, $context);
    }

    /**
     * Checks that an action that ran had the effect it promised. A failed check is reported to the agent and to the
     * humans; when the action names an undo, it is run straight away only if it is itself low risk with declared
     * safeguards, and otherwise filed for approval like any other action.
     *
     * @param  array<string, mixed>  $context
     */
    private function verify(Machine $machine, ?AgentRun $run, string $actionName, array $context): string
    {
        $action = $this->catalog->get($actionName);
        $verification = $action instanceof Verifiable ? $action->verification($context['arguments'] ?? []) : null;

        if (! $verification) {
            return '';
        }

        if ($delay = config('sentinel.gate.verify_delay_seconds')) {
            sleep($delay);
        }

        try {
            $result = $this->transport->run($machine, $verification->command, SafeExecutor::TIMEOUT_SECONDS);
            $passed = ($verification->passes)($result);
        } catch (Throwable $e) {
            $result = new CommandResult($e->getMessage(), -1);
            $passed = false;
        }

        $details = ['command' => $verification->command, 'expected' => $verification->expectation, 'exit_code' => $result->exitCode, 'output_excerpt' => mb_strcut($result->output, 0, 2000)];

        if ($passed) {
            $this->audit->record($machine, $run, 'action_verified', $actionName, $details);

            return "\nVERIFIED: {$verification->expectation}.";
        }

        $this->audit->record($machine, $run, 'action_verification_failed', $actionName, $details);
        $message = "\nVERIFICATION FAILED: expected {$verification->expectation}, the check said: ".trim(mb_strcut($result->output, 0, 300));
        $message .= $verification->rollback ? $this->rollback($machine, $run, $actionName, $verification->rollback) : '';

        app(Notifier::class)->machineAlert($machine, "{$actionName} did not have the expected effect on {$machine->name}", trim($message), evenWhenQuiet: true);

        return $message;
    }

    /** @param array{0: string, 1: array<string, mixed>} $rollback */
    private function rollback(Machine $machine, ?AgentRun $run, string $actionName, array $rollback): string
    {
        [$name, $arguments] = $rollback;
        $undo = $this->catalog->get($name);
        $command = $undo->command($arguments);

        if ($undo->risk() === RiskLevel::Low && $undo instanceof HasSafeguards) {
            $output = $this->run($machine, $run, $name, $command, ['arguments' => $arguments, 'rollback_of' => $actionName], 'action_rolled_back');

            return "\nROLLED BACK with {$name}: ".trim($output);
        }

        $pending = PendingAction::create([
            'machine_id' => $machine->id, 'agent_run_id' => $run?->id, 'action' => $name, 'arguments' => $arguments,
            'command' => $command, 'risk' => $undo->risk()->value, 'reason' => "Undo of {$actionName}, whose check failed right after it ran.",
        ]);
        $this->audit->record($machine, $run, 'action_pending', $name, ['arguments' => $arguments, 'command' => $command, 'pending_action_id' => $pending->id, 'rollback_of' => $actionName]);
        app(Notifier::class)->approvalNeeded($pending);

        return "\nUNDO FILED for approval (#{$pending->id}): {$name}.";
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
