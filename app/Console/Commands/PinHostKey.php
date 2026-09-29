<?php

namespace App\Console\Commands;

use App\Models\Machine;
use Illuminate\Console\Command;
use phpseclib3\Net\SSH2;

use function Laravel\Prompts\confirm;

class PinHostKey extends Command
{
    protected $signature = 'sentinel:pin-host-key {machine : Machine id}';

    protected $description = 'Fetch the SSH host key of a machine and pin its fingerprint after human confirmation';

    public function handle(): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        $ssh = new SSH2($machine->host, $machine->port, 10);
        $key = $ssh->getServerPublicHostKey();

        if (! $key) {
            $this->error('Could not read the host key.');

            return self::FAILURE;
        }

        $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', $key, true)), '=');
        $this->line("Host key of {$machine->name}: {$fingerprint}");

        if (confirm('Verify it out-of-band, then pin it?', false)) {
            $machine->update(['host_key_fingerprint' => $fingerprint]);
            $this->info('Pinned.');
        }

        return self::SUCCESS;
    }
}
