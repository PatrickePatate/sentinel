<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Ssh\AccessControl;
use App\Ssh\Provisioning\RevokeScript;
use Illuminate\Console\Command;

class RevokeMachine extends Command
{
    protected $signature = 'sentinel:revoke {machine : Machine id} {--purge : Also delete the remote user and Sentinel fail2ban files} {--lift : Lift a revocation (after re-provisioning) instead of revoking}';

    protected $description = 'Cut Sentinel off a machine: block it locally at once and print the root script that removes access remotely';

    public function handle(RevokeScript $script, AccessControl $access): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        if ($this->option('lift')) {
            $access->lift($machine);
            $this->getOutput()->getErrorStyle()->writeln("Revocation lifted for {$machine->name}. Re-run sentinel:provision on the machine.");

            return self::SUCCESS;
        }

        $cancelled = $access->revoke($machine, (bool) $this->option('purge'));

        // Status goes to stderr so stdout stays pipeable: php artisan sentinel:revoke 1 | ssh root@host bash
        $this->getOutput()->getErrorStyle()->writeln("Sentinel is now blocked from {$machine->name} ({$cancelled} pending action(s) cancelled). Run the script below as root on the machine to remove access there too.");

        $this->output->write($script->render($machine, (bool) $this->option('purge')));

        return self::SUCCESS;
    }
}
