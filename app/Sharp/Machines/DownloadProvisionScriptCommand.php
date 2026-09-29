<?php

namespace App\Sharp\Machines;

use App\Models\Machine;
use App\Ssh\Provisioning\ProvisionScript;
use Code16\Sharp\EntityList\Commands\InstanceCommand;
use Illuminate\Support\Str;

class DownloadProvisionScriptCommand extends InstanceCommand
{
    public function label(): string
    {
        return 'Download provisioning script';
    }

    public function buildCommandConfig(): void
    {
        $this->configureDescription('Run the downloaded script once, as root, on the machine. It creates the restricted user and grants only what the agent needs. Review it first. The key is restricted to SENTINEL_SOURCE_IPS when that is set.');
    }

    public function execute(mixed $instanceId, array $data = []): array
    {
        $machine = Machine::findOrFail($instanceId);
        $script = app(ProvisionScript::class)->render($machine);

        activity('ssh')->performedOn($machine)->causedBy(auth()->user())->event('provision_script_downloaded')->log('provision script downloaded');

        return $this->streamDownload($script, 'sentinel-provision-'.Str::slug($machine->name).'.sh');
    }
}
