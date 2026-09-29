<?php

namespace App\Ssh\Tools;

/**
 * A read-only tool that maps to a constant command and takes no arguments.
 */
class FixedCommandTool implements Tool
{
    public function __construct(
        private string $name,
        private string $description,
        private string $command,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
    }

    public function command(array $arguments): string
    {
        if ($arguments !== []) {
            throw new InvalidToolArguments("Tool {$this->name} takes no arguments.");
        }

        return $this->command;
    }
}
