<?php

namespace App\Ssh;

final readonly class CommandResult
{
    public function __construct(
        public string $output,
        public int $exitCode,
    ) {}
}
