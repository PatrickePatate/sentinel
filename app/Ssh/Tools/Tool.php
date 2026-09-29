<?php

namespace App\Ssh\Tools;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON schema of the arguments the model may pass.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Builds the fixed, read-only shell command. Must validate and escape every
     * argument, and throw InvalidToolArguments on anything unexpected.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function command(array $arguments): string;
}
