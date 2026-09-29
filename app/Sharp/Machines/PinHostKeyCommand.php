<?php

namespace App\Sharp\Machines;

use App\Models\Machine;
use App\Ssh\AuditTrail;
use App\Ssh\HostKeyFingerprint;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Code16\Sharp\Form\Fields\SharpFormTextField;
use Code16\Sharp\Utils\Fields\FieldsContainer;
use Throwable;

class PinHostKeyCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Pin host key';
    }

    public function buildCommandConfig(): void
    {
        $this->configureDescription('Paste the fingerprint you verified out-of-band (e.g. from the provider console). It is pinned only if the server presents exactly this key.');
    }

    public function buildFormFields(FieldsContainer $formFields): void
    {
        $formFields->addField(SharpFormTextField::make('fingerprint')->setLabel('Verified fingerprint (SHA256:...)'));
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        $this->validate($data, ['fingerprint' => ['required', 'string']]);

        $machine = Machine::findOrFail($instanceId);

        try {
            $actual = HostKeyFingerprint::fetch($machine);
        } catch (Throwable $e) {
            report($e);

            return $this->info('Connection failed (see the application log).');
        }

        if (! $actual || ! hash_equals($actual, trim($data['fingerprint']))) {
            return $this->info('Fingerprint does not match what the server presents. Nothing was pinned.');
        }

        $machine->update(['host_key_fingerprint' => $actual]);
        app(AuditTrail::class)->record($machine, null, 'host_key_pinned', 'host_key_pinned', ['fingerprint' => $actual]);

        return $this->refresh($instanceId);
    }
}
