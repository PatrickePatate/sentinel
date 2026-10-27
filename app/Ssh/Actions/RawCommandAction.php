<?php

namespace App\Ssh\Actions;

use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Any shell command, run as root. Only reachable during an unguarded session, and never without a human approving that
 * exact command: the agent can only file it (see ActionExecutor::proposeCommand), it never runs by itself.
 *
 * command() is what the human reviews: what runs as root. It is sent to the machine inside a token signed for that one
 * run (see UnguardedSession::runCommand), never through sudo, which the Sentinel account has no shell rights in.
 */
class RawCommandAction implements ActionTool, HumanOnly
{
    public const MAX_LENGTH = 2000;

    public function name(): string
    {
        return 'run_command';
    }

    public function description(): string
    {
        return 'Run a shell command as root during an unguarded session, after a human approved this exact command.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::High;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()->description('The shell command, run by bash as root. One command or a short pipeline; no interactive programs.')->required(),
        ];
    }

    public function command(array $arguments): string
    {
        $command = $arguments['command'] ?? null;

        if (! is_string($command) || trim($command) === '' || strlen($command) > self::MAX_LENGTH || str_contains($command, "\0")) {
            throw new InvalidToolArguments('command must be a non-empty string of at most '.self::MAX_LENGTH.' characters.');
        }

        return 'bash -c '.escapeshellarg($command);
    }
}
