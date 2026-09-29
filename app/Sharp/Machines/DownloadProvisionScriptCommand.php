<?php

namespace App\Sharp\Machines;

use App\Models\Machine;
use App\Ssh\Provisioning\ProvisionScript;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Code16\Sharp\Form\Fields\SharpFormTextField;
use Code16\Sharp\Utils\Fields\FieldsContainer;
use Illuminate\Support\Str;

class DownloadProvisionScriptCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Download provisioning script';
    }

    public function buildCommandConfig(): void
    {
        $this->configureDescription('Run the downloaded script once, as root, on the machine. It creates the restricted user and grants only what the agent needs. Review it first.');
    }

    public function buildFormFields(FieldsContainer $formFields): void
    {
        $formFields->addField(
            SharpFormTextField::make('from')
                ->setLabel('Only accept this key from IP (recommended)')
                ->setHelpMessage('Public IP of the server running Sentinel. Leave empty to allow the key from anywhere.')
                ->setMaxLength(45)
        );
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        $this->validate($data, ['from' => ['nullable', 'ip']]);

        $machine = Machine::findOrFail($instanceId);
        $script = app(ProvisionScript::class)->render($machine, $data['from'] ?: null);

        activity('ssh')->performedOn($machine)->causedBy(auth()->user())->event('provision_script_downloaded')->log('provision script downloaded');

        return $this->streamDownload($script, 'sentinel-provision-'.Str::slug($machine->name).'.sh');
    }
}
