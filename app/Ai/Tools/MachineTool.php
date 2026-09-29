<?php

namespace App\Ai\Tools;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Ssh\SafeExecutor;
use App\Ssh\Tools\Tool as CatalogTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Exposes one catalog tool to the Laravel AI agent, bound to a machine.
 * All execution still goes through SafeExecutor.
 */
class MachineTool implements Tool
{
    public function __construct(
        private CatalogTool $tool,
        private Machine $machine,
        private SafeExecutor $executor,
        private ?AgentRun $run = null,
    ) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->executor->execute($this->machine, $this->tool->name(), $request->all(), $this->run);
    }
}
