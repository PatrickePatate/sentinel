<?php

namespace App\Ssh\Gate;

use App\Models\Machine;
use App\Ssh\Actions\ActionTool;
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
    public function assess(Machine $machine, ActionTool $action, string $command, string $objective): GateDecision
    {
        if ($action->risk() === RiskLevel::High) {
            return new GateDecision(GateVerdict::Refuse, 'High-risk actions are never executed by the agent.');
        }

        if ($action->risk() === RiskLevel::Medium) {
            return new GateDecision(GateVerdict::AskHuman, 'Medium-risk actions always require human approval.');
        }

        if (! $machine->autonomy_enabled) {
            return new GateDecision(GateVerdict::AskHuman, 'Autonomy is disabled for this machine.');
        }

        try {
            $answers = Classification::of([
                'machine' => $machine->name,
                'environment' => $machine->environment,
                'objective' => $objective,
                'action' => $action->name(),
                'description' => $action->description(),
                'command' => $command,
            ])->questions([
                'destructive' => new Boolean('Could running this command on a production server delete data or interrupt a service?'),
                'reversible' => new Boolean('Can the effect of this command be undone or is it purely regenerable housekeeping?'),
                'matches_objective' => new Boolean('Is this command a reasonable step toward the stated objective?'),
                'verdict' => new Choice('What should happen with this command?', [
                    'execute' => 'Safe to run automatically',
                    'ask_human' => 'A human should confirm first',
                    'refuse' => 'Must not run',
                ]),
            ])->classify(config('sentinel.gate.provider'), config('sentinel.gate.model'));
        } catch (Throwable $e) {
            return new GateDecision(GateVerdict::Refuse, 'Risk model unavailable, failing closed: '.$e->getMessage());
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
            && $scores['destructive'] <= config('sentinel.gate.max_destructive')
            && $scores['reversible'] >= config('sentinel.gate.min_reversible')
            && $scores['matches_objective'] >= config('sentinel.gate.min_matches_objective');

        return $confident
            ? new GateDecision(GateVerdict::Execute, 'Low risk and high model confidence.', $scores)
            : new GateDecision(GateVerdict::AskHuman, 'Model confidence too low for autonomous execution.', $scores);
    }
}
