<?php

namespace App\Sharp\Machines;

use App\Ai\ScanRunner;
use App\Jobs\RunScan;
use App\Models\Machine;
use App\Sharp\Entities\AgentRunEntity;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Code16\Sharp\Form\Fields\SharpFormTextareaField;
use Code16\Sharp\Utils\Fields\FieldsContainer;
use Code16\Sharp\Utils\Links\LinkToShowPage;
use Illuminate\Support\Facades\RateLimiter;

class ScanMachineCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Run an AI scan';
    }

    public function buildCommandConfig(): void
    {
        $this->configureDescription('The agent inspects the machine through the read-only tool catalog. You are taken to the scan page to watch the report as it is written.');
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

        $run = app(ScanRunner::class)->queue(Machine::findOrFail($instanceId), $data['objective']);
        RunScan::dispatch((int) $instanceId, $data['objective'], auth()->id(), 'manual', $run->id);

        // Straight to the scan page, where the report fills in live.
        return $this->link(LinkToShowPage::make(AgentRunEntity::class, (string) $run->id)->renderAsUrl());
    }
}
