<?php

namespace App\Ssh;

use App\Models\AgentRun;
use App\Models\Machine;
use App\Ssh\Tools\InvalidToolArguments;
use InvalidArgumentException;
use Throwable;

/**
 * Single entry point to run something on a machine. Resolves a named tool from
 * the catalog, audits the call, bounds the output. There is no raw-command path.
 */
class SafeExecutor
{
    public const MAX_OUTPUT_BYTES = 8000;

    public const TIMEOUT_SECONDS = 20;

    public function __construct(
        private ToolCatalog $catalog,
        private SshTransport $transport,
        private AuditTrail $audit = new AuditTrail,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return string Output safe to hand to the model (never throws for tool-level errors).
     */
    public function execute(Machine $machine, string $toolName, array $arguments = [], ?AgentRun $run = null): string
    {
        try {
            $tool = $this->catalog->get($toolName);
            $command = $tool->command($arguments);
        } catch (InvalidArgumentException|InvalidToolArguments $e) {
            $this->audit($machine, $run, $toolName, $arguments, '(rejected)', null, $e->getMessage(), 'rejected');

            return 'ERROR: '.$e->getMessage();
        }

        try {
            $result = $this->transport->run($machine, $command, self::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            $this->audit($machine, $run, $toolName, $arguments, $command, null, $e->getMessage(), 'failed');

            return 'ERROR: '.$e->getMessage();
        }

        $output = $this->truncate($result->output);
        $this->audit($machine, $run, $toolName, $arguments, $command, $result->exitCode, $output, 'ok');

        return $output === '' ? "(no output, exit code {$result->exitCode})" : $output;
    }

    private function truncate(string $output): string
    {
        $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');

        return strlen($output) > self::MAX_OUTPUT_BYTES
            ? substr($output, 0, self::MAX_OUTPUT_BYTES)."\n[output truncated]"
            : $output;
    }

    /** @param array<string, mixed> $arguments */
    private function audit(Machine $machine, ?AgentRun $run, string $tool, array $arguments, string $command, ?int $exitCode, string $excerpt, string $status): void
    {
        $this->audit->record($machine, $run, $status, $tool, [
            'tool' => $tool,
            'arguments' => $arguments,
            'command' => $command,
            'exit_code' => $exitCode,
            'output_excerpt' => $excerpt,
        ]);
    }
}
