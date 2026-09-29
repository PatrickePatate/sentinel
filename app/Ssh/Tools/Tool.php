<?php

namespace App\Ssh\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * Arguments the model may pass (Laravel AI JSON schema types).
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;

    /**
     * Builds the fixed, read-only shell command. Must validate and escape every
     * argument, and throw InvalidToolArguments on anything unexpected.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function command(array $arguments): string;
}
