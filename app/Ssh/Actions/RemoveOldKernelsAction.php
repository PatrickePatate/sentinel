<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class RemoveOldKernelsAction implements ActionTool, HasSafeguards, RequiresSudo, Verifiable
{
    use UsesSudo;

    public const WRAPPER = '/usr/local/sbin/sentinel-kernel-cleanup';

    public function name(): string
    {
        return 'remove_old_kernels';
    }

    public function description(): string
    {
        return 'Purge old kernel packages (image, headers, modules) to free /boot and disk space. Always keeps two kernels: the running one and the newest other one. Always needs human approval.';
    }

    public function safeguards(): string
    {
        return 'Enforced by the root wrapper: the running kernel and the newest other kernel (a fallback to boot) are never removed; apt is simulated first and the wrapper refuses if it would remove any package that is not an old kernel package, or install anything.';
    }

    public function risk(): RiskLevel
    {
        return RiskLevel::Medium;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function command(array $arguments): string
    {
        if ($arguments !== []) {
            throw new InvalidToolArguments('remove_old_kernels takes no arguments.');
        }

        return $this->sudo().self::WRAPPER.' 2>&1';
    }

    public function sudoRules(): array
    {
        return [self::WRAPPER.' *'];
    }

    /** The machine must still be able to boot what it runs now. */
    public function verification(array $arguments): ?Verification
    {
        return new Verification(
            "dpkg-query -W -f='\${Status}' -- \"linux-image-\$(uname -r)\" 2>&1",
            fn ($r) => trim($r->output) === 'install ok installed',
            'the running kernel is still installed',
        );
    }
}
