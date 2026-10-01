<?php

namespace App\Ssh\Gate;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Ssh\Actions\ActionTool;
use App\Ssh\Actions\HasSafeguards;
use App\Ssh\Actions\RiskLevel;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Throwable;

/**
 * Decides whether a corrective action may run on its own. Deterministic rules
 * come first and can never be relaxed by the model; the System One model (Jev)
 * is only consulted for low-risk actions and can only escalate, never unlock.
 */
class RiskGate
{
    public function assess(Machine $machine, ActionTool $action, string $command, string $objective, ?AgentRun $run = null): GateDecision
    {
        if ($action->risk() === RiskLevel::High) {
            return new GateDecision(GateVerdict::Refuse, 'High-risk actions are never executed by the agent.');
        }

        // An explicit, revocable grant by an administrator for this one action on this one machine (see the approvals page).
        if ($machine->trusts($action->name()) && $action instanceof HasSafeguards) {
            return new GateDecision(GateVerdict::Execute, 'Allowed by an administrator for this machine.');
        }

        // Autonomy comes from the machine settings, or from an administrator switching it on for this one scan.
        if (! $machine->autonomy_enabled && ! $run?->allow_actions) {
            return new GateDecision(GateVerdict::AskHuman, 'Autonomy is disabled for this machine and was not enabled for this scan.');
        }

        if ($action->risk() === RiskLevel::Medium && ! $machine->autonomy_medium) {
            return new GateDecision(GateVerdict::AskHuman, 'Medium-risk actions require human approval on this machine.');
        }

        try {
            $answers = Classification::of([
                'machine' => $machine->name,
                'environment' => $machine->environment,
                'objective' => $objective,
                'action' => $action->name(),
                'description' => $action->description(),
                'command' => $command,
                'safeguards' => $action instanceof HasSafeguards ? $action->safeguards() : 'none declared',
            ])->questions([
                'destructive' => new Boolean('Could running this command on a production server delete data or interrupt a service that is currently running correctly (taking into account the declared safeguards)?'),
                'reversible' => new Boolean('Can the effect of this command be undone, is it purely regenerable housekeeping, or does it only bring something back that was already down (taking into account the declared safeguards)?'),
                'matches_objective' => new Boolean('Is this command a reasonable step toward the stated objective?'),
                'verdict' => new Choice('What should happen with this command?', [
                    'execute' => 'Safe to run automatically',
                    'ask_human' => 'A human should confirm first',
                    'refuse' => 'Must not run',
                ]),
            ])->classify(config('sentinel.gate.provider'), config('sentinel.gate.model'));
        } catch (Throwable $e) {
            return new GateDecision(GateVerdict::Refuse, 'Risk model unavailable, failing closed.', ['error' => $e->getMessage()]);
        }

        $verdict = $answers->answer('verdict');
        $scores = [
            'destructive' => $answers->answer('destructive')->probability,
            'reversible' => $answers->answer('reversible')->probability,
            'matches_objective' => $answers->answer('matches_objective')->probability,
            'execute' => $verdict->probabilityOf('execute'),
            'refuse' => $verdict->probabilityOf('refuse'),
        ];

        if ($verdict->choice === 'refuse' && $scores['refuse'] >= 0.5) {
            return new GateDecision(GateVerdict::Refuse, 'Risk model says this command must not run.', $scores);
        }

        $confident = $verdict->choice === 'execute'
            && $scores['execute'] >= config('sentinel.gate.min_execute_confidence')
            && $scores['destructive'] <= $machine->gate('max_destructive')
            && $scores['reversible'] >= $machine->gate('min_reversible')
            && $scores['matches_objective'] >= config('sentinel.gate.min_matches_objective');

        return $confident
            ? new GateDecision(GateVerdict::Execute, 'Low risk and high model confidence.', $scores)
            : new GateDecision(GateVerdict::AskHuman, 'Model confidence too low for autonomous execution.', $scores);
    }
}
