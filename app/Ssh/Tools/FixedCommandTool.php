<?php

namespace App\Ssh\Tools;

use App\Ssh\Provisioning\RequiresSudo;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A read-only tool that maps to a constant command and takes no arguments.
 */
class FixedCommandTool implements RequiresSudo, Tool
{
    public function __construct(
        private string $name,
        private string $description,
        private string $command,
        /** @var list<string> */
        private array $sudoRules = [],
    ) {}

    public function sudoRules(): array
    {
        return $this->sudoRules;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function command(array $arguments): string
    {
        if ($arguments !== []) {
            throw new InvalidToolArguments("Tool {$this->name} takes no arguments.");
        }

        return str_replace('{sudo}', config('sentinel.actions.use_sudo') ? 'sudo -n ' : '', $this->command);
    }
}
