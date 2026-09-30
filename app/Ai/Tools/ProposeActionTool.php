<?php

namespace App\Ai\Tools;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Ssh\ActionExecutor;
use App\Ssh\Actions\ActionTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets the agent turn a fix it recommends into an approvable request, linked to the current run.
 * Nothing runs: the action is filed as a PendingAction that a human approves (or not) from the UI.
 */
class ProposeActionTool implements Tool
{
    public function __construct(
        private ActionTool $action,
        private Machine $machine,
        private ActionExecutor $executor,
        private ?AgentRun $run,
    ) {}

    public function name(): string
    {
        return 'propose_'.$this->action->name();
    }

    public function description(): Stringable|string
    {
        return 'Propose, never run: file "'.$this->action->name().'" for human approval as a recommended fix. '
            .$this->action->description().' Use it for each concrete remediation you recommend that this action covers.';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->action->schema($schema) + [
            'rationale' => $schema->string()->description('Why this fix is needed, citing the evidence you collected (one or two sentences).')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->all();

        return $this->executor->propose($this->machine, $this->action->name(), Arr::except($arguments, 'rationale'), $this->run, (string) ($arguments['rationale'] ?? ''));
    }
}
