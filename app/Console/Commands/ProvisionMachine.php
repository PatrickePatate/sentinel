<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Ssh\Provisioning\ProvisionScript;
use App\Ssh\Provisioning\SudoersBuilder;
use Illuminate\Console\Command;

class ProvisionMachine extends Command
{
    protected $signature = 'sentinel:provision {machine : Machine id} {--from= : Only accept the SSH key from this IP (recommended)} {--sudoers : Print only the sudoers policy} {--output= : Write the script to this file (mode 0700) instead of printing it}';

    protected $description = 'Print the script to run as root on a machine to give Sentinel restricted, audited access';

    public function handle(ProvisionScript $script, SudoersBuilder $sudoers): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        $content = $this->option('sudoers')
            ? $sudoers->render($machine->username)
            : $script->render($machine, $this->option('from') ?: null);

        if ($path = $this->option('output')) {
            file_put_contents($path, $content);
            chmod($path, 0700);
            $this->getOutput()->getErrorStyle()->writeln("Script written to {$path}. Run it as root on {$machine->name}: sudo bash {$path}");

            return self::SUCCESS;
        }

        $this->output->write($content);

        return self::SUCCESS;
    }
}
