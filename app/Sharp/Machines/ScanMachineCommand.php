<?php

namespace App\Sharp\Machines;

use App\Jobs\RunScan;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Code16\Sharp\Form\Fields\SharpFormTextareaField;
use Code16\Sharp\Utils\Fields\FieldsContainer;
use Illuminate\Support\Facades\RateLimiter;

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

        $throttleKey = 'scan:'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttleKey, config('sentinel.limits.scans_per_hour_per_user'))) {
            return $this->info('Scan limit reached for this hour. Try again later.');
        }

        RateLimiter::hit($throttleKey, 3600);

        RunScan::dispatch((int) $instanceId, $data['objective'], auth()->id());

        return $this->info('Scan queued. Follow it in the Scans menu.');
    }
}
