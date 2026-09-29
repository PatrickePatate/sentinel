<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Ssh\Provisioning\ProvisionScript;
use App\Ssh\Provisioning\SudoersBuilder;
use Illuminate\Console\Command;

class ProvisionMachine extends Command
{
    protected $signature = 'sentinel:provision {machine : Machine id} {--from= : Only accept the SSH key from this IP (recommended)} {--sudoers : Print only the sudoers policy}';

    protected $description = 'Print the script to run as root on a machine to give Sentinel restricted, audited access';

    public function handle(ProvisionScript $script, SudoersBuilder $sudoers): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        $this->output->write($this->option('sudoers')
            ? $sudoers->render($machine->username)
            : $script->render($machine, $this->option('from') ?: null));

        return self::SUCCESS;
    }
}
