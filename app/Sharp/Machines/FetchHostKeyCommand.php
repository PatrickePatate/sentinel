<?php

namespace App\Sharp\Machines;

use App\Models\Machine;
use App\Ssh\HostKeyFingerprint;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Throwable;

class FetchHostKeyCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Show current host key';
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        try {
            $fingerprint = HostKeyFingerprint::fetch(Machine::findOrFail($instanceId));
        } catch (Throwable $e) {
            report($e);

            return $this->info('Connection failed (see the application log).');
        }

        return $this->info($fingerprint
            ? "Server host key: {$fingerprint} — compare it out-of-band, then pin it."
            : 'Could not read the host key.');
    }
}
