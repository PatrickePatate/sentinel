<?php

namespace App\Ssh;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\Gate\GateVerdict;
use App\Ssh\Gate\RiskGate;
use App\Ssh\Tools\InvalidToolArguments;
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

        if ($decision->verdict === GateVerdict::Execute && $this->quotaReached($run)) {
            $decision = new Gate\GateDecision(GateVerdict::AskHuman, 'Autonomous action quota reached for this scan.', $decision->details);
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

            return "PENDING_HUMAN_APPROVAL (#{$pending->id}): {$decision->reason} Not executed; mention it in your report.";
        }

        return $this->run($machine, $run, $actionName, $command, $context, 'action_executed');
    }

    /**
     * Executes an action a human explicitly approved. The command is re-derived
     * from the catalog, never taken from the stored row.
     */
    public function approve(PendingAction $pending): string
    {
        abort_unless($pending->status === 'pending', 409, 'Action already decided.');

        $machine = $pending->machine;

        try {
            $command = $this->catalog->get($pending->action)->command($pending->arguments ?? []);
        } catch (InvalidArgumentException|InvalidToolArguments $e) {
            $pending->update(['status' => 'failed', 'output' => $e->getMessage(), 'decided_at' => now()]);

            return 'ERROR: '.$e->getMessage();
        }

        $output = $this->run($machine, $pending->agentRun ?? null, $pending->action, $command, ['approved_pending_action_id' => $pending->id], 'action_approved');
        $pending->update(['status' => str_starts_with($output, 'ERROR') ? 'failed' : 'executed', 'output' => $output, 'decided_at' => now()]);

        return $output;
    }

    public function reject(PendingAction $pending): void
    {
        abort_unless($pending->status === 'pending', 409, 'Action already decided.');

        $pending->update(['status' => 'rejected', 'decided_at' => now()]);
        $this->audit->record($pending->machine, null, 'action_rejected', $pending->action, ['pending_action_id' => $pending->id]);
    }

    /** @param array<string, mixed> $context */
    private function run(Machine $machine, ?AgentRun $run, string $action, string $command, array $context, string $event): string
    {
        try {
            $result = $this->transport->run($machine, $command, SafeExecutor::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            $this->audit->record($machine, $run, 'action_failed', $action, $context + ['command' => $command, 'error' => $e->getMessage()]);

            return 'ERROR: '.$e->getMessage();
        }

        $output = mb_strcut(mb_convert_encoding($result->output, 'UTF-8', 'UTF-8'), 0, SafeExecutor::MAX_OUTPUT_BYTES);
        $this->audit->record($machine, $run, $event, $action, $context + ['command' => $command, 'exit_code' => $result->exitCode, 'output_excerpt' => $output]);

        return $output === '' ? "(no output, exit code {$result->exitCode})" : $output;
    }

    private function quotaReached(?AgentRun $run): bool
    {
        if ($run === null) {
            return false;
        }

        return Activity::query()
            ->where('log_name', 'ssh')
            ->where('event', 'action_executed')
            ->where('properties->agent_run_id', $run->id)
            ->count() >= config('sentinel.gate.max_autonomous_actions_per_run');
    }
}
