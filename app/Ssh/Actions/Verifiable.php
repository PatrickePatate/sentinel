<?php

namespace App\Ssh\Actions;

/** An action whose effect can be checked right after it ran (see ActionExecutor). */
interface Verifiable
{
    /** @param array<string, mixed> $arguments The arguments the action ran with (already validated). */
    public function verification(array $arguments): ?Verification;
}
