<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Ssh\Provisioning\ProvisionScript;
use App\Ssh\Provisioning\SudoersBuilder;
use Illuminate\Console\Command;

class ProvisionMachine extends Command
{
    protected $signature = 'sentinel:provision {machine : Machine id} {--sudoers : Print only the sudoers policy} {--output= : Write the script to this file (mode 0700) instead of printing it} {--one-liner : Issue a one-hour link and print the command to run as root on the machine (curl ... | sudo bash)}';

    protected $description = 'Print the script to run as root on a machine to give Sentinel restricted, audited access';

    public function handle(ProvisionScript $script, SudoersBuilder $sudoers): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        if ($this->option('one-liner')) {
            $machine->issueProvisionToken();
            $this->line("curl -fsSL '{$machine->provisionUrl()}' | sudo bash");
            $this->getOutput()->getErrorStyle()->writeln('Valid for one hour. The machine must be able to reach '.config('app.url').' (set APP_URL), and Sentinel must be able to SSH to the machine.');

            return self::SUCCESS;
        }

        $content = $this->option('sudoers')
            ? $sudoers->render($machine->username)
            : $script->render($machine);

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
