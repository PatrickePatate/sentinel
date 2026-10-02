<?php

namespace App\Ssh\Actions;

use App\Ssh\CommandResult;
use Closure;

/**
 * A read-only check run right after an action, to tell "the command ran" apart from "the problem is fixed".
 * Optionally names the action that undoes it when the check fails.
 */
final class Verification
{
    /**
     * @param  Closure(CommandResult): bool  $passes
     * @param  array{0: string, 1: array<string, mixed>}|null  $rollback  [action name, arguments]
     */
    public function __construct(
        public readonly string $command,
        public readonly Closure $passes,
        public readonly string $expectation,
        public readonly ?array $rollback = null,
    ) {}

    public static function unitActive(string $unit): self
    {
        return new self('systemctl is-active -- '.escapeshellarg($unit).' 2>&1', fn (CommandResult $r) => trim($r->output) === 'active', "{$unit} is active");
    }

    public static function packageInstalled(string $package): self
    {
        return new self("dpkg-query -W -f='\${Status}' -- ".escapeshellarg($package).' 2>&1', fn (CommandResult $r) => trim($r->output) === 'install ok installed', "{$package} is installed");
    }
}
