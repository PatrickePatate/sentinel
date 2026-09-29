<?php

namespace App\Ai\Tools;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Ssh\ActionExecutor;
use App\Ssh\Actions\ActionTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Exposes a corrective action to the agent. The call is only a request: the
 * ActionExecutor and RiskGate decide whether anything actually runs.
 */
class MachineActionTool implements Tool
{
    public function __construct(
        private ActionTool $action,
        private Machine $machine,
        private ActionExecutor $executor,
        private ?AgentRun $run,
        private string $objective,
    ) {}

    public function name(): string
    {
        return $this->action->name();
    }

    public function description(): Stringable|string
    {
        return $this->action->description().' (Requests are risk-checked; may be refused or held for human approval.)';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->action->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->executor->request($this->machine, $this->action->name(), $request->all(), $this->run, $this->objective);
    }
}
