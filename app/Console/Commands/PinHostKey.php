<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Ssh\HostKeyFingerprint;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class PinHostKey extends Command
{
    protected $signature = 'sentinel:pin-host-key {machine : Machine id}';

    protected $description = 'Fetch the SSH host key of a machine and pin its fingerprint after human confirmation';

    public function handle(): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        $fingerprint = HostKeyFingerprint::fetch($machine);

        if (! $fingerprint) {
            $this->error('Could not read the host key.');

            return self::FAILURE;
        }

        $this->line("Host key of {$machine->name}: {$fingerprint}");

        if (confirm('Verify it out-of-band, then pin it?', false)) {
            $machine->update(['host_key_fingerprint' => $fingerprint]);
            $this->info('Pinned.');
        }

        return self::SUCCESS;
    }
}
