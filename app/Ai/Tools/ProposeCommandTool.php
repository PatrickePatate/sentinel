<?php

namespace App\Ai\Tools;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Ssh\ActionExecutor;
use App\Ssh\Actions\RawCommandAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Only offered during an unguarded session. Files one shell command for a human to approve; nothing runs until then.
 */
class ProposeCommandTool implements Tool
{
    public function __construct(
        private Machine $machine,
        private ActionExecutor $executor,
        private ?AgentRun $run,
    ) {}

    public function name(): string
    {
        return 'propose_command';
    }

    public function description(): Stringable|string
    {
        return 'Propose, never run: file one shell command (run as root by bash) for the administrator to approve in the chat. It only runs if they approve that exact command; you learn the outcome in their next message.';
    }

    public function schema(JsonSchema $schema): array
    {
        return (new RawCommandAction)->schema($schema) + [
            'rationale' => $schema->string()->description('Why this command is needed, citing the evidence you collected (one or two sentences).')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->all();

        return $this->executor->proposeCommand($this->machine, (string) ($arguments['command'] ?? ''), $this->run, (string) ($arguments['rationale'] ?? ''));
    }
}
