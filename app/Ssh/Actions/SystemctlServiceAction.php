<?php

namespace App\Ssh\Actions;

use App\Ssh\Provisioning\RequiresSudo;
use App\Ssh\Tools\InvalidToolArguments;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A systemctl verb on a unit from an explicit allowlist. The allowlist is also what
 * feeds the per-unit sudoers rules, so no wildcard is ever needed.
 */
abstract class SystemctlServiceAction implements ActionTool, RequiresSudo, Verifiable
{
    use UsesSudo;

    abstract protected function verb(): string;

    /** @return list<string> */
    abstract protected function allowedServices(): array;

    public function schema(JsonSchema $schema): array
    {
        return ['service' => $schema->string()->description('Unit name from the allowlist')->required()];
    }

    public function command(array $arguments): string
    {
        $service = $arguments['service'] ?? null;

        if (! is_string($service) || ! in_array($service, $this->allowedServices(), true)) {
            throw new InvalidToolArguments('Service is not on the allowlist for this action.');
        }

        return $this->sudo()."systemctl {$this->verb()} -- ".escapeshellarg($service).' 2>&1';
    }

    public function sudoRules(): array
    {
        return array_map(fn (string $service) => "/usr/bin/systemctl {$this->verb()} -- {$service}", $this->allowedServices());
    }

    /** @return list<string> */
    protected static function list(string $key): array
    {
        return array_values(array_filter(config("sentinel.actions.{$key}", [])));
    }

    public function verification(array $arguments): ?Verification
    {
        return Verification::unitActive($arguments['service']);
    }
}
