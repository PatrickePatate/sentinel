<?php

namespace App\Sharp\Machines;

use App\Jobs\RunScan;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Code16\Sharp\Form\Fields\SharpFormTextareaField;
use Code16\Sharp\Utils\Fields\FieldsContainer;

class ScanMachineCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Run an AI scan';
    }

    public function buildCommandConfig(): void
    {
        $this->configureDescription('The agent inspects the machine through the read-only tool catalog. Runs in the background.');
    }

    public function buildFormFields(FieldsContainer $formFields): void
    {
        $formFields->addField(SharpFormTextareaField::make('objective')->setLabel('Objective')->setRowCount(3));
    }

    protected function initialData(mixed $instanceId): array
    {
        return ['objective' => 'Run a security and health audit'];
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        $this->validate($data, ['objective' => ['required', 'string', 'max:1000']]);

        RunScan::dispatch((int) $instanceId, $data['objective']);

        return $this->info('Scan queued. Follow it in the Scans menu.');
    }
}
