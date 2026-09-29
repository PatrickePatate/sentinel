<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\AuditTrail;
use App\Ssh\Provisioning\RevokeScript;
use Illuminate\Console\Command;

class RevokeMachine extends Command
{
    protected $signature = 'sentinel:revoke {machine : Machine id} {--purge : Also delete the remote user and Sentinel fail2ban files} {--lift : Lift a revocation (after re-provisioning) instead of revoking}';

    protected $description = 'Cut Sentinel off a machine: block it locally at once and print the root script that removes access remotely';

    public function handle(RevokeScript $script, AuditTrail $audit): int
    {
        $machine = Machine::findOrFail($this->argument('machine'));

        if ($this->option('lift')) {
            $machine->forceFill(['revoked_at' => null])->save();
            $audit->record($machine, null, 'machine_restored', 'revocation lifted');
            $this->getOutput()->getErrorStyle()->writeln("Revocation lifted for {$machine->name}. Re-run sentinel:provision on the machine.");

            return self::SUCCESS;
        }

        $machine->forceFill(['revoked_at' => $machine->revoked_at ?? now(), 'autonomy_enabled' => false])->save();

        $cancelled = PendingAction::where('machine_id', $machine->id)->where('status', 'pending')
            ->update(['status' => 'rejected', 'decided_at' => now()]);

        $audit->record($machine, null, 'machine_revoked', 'access revoked', ['purge' => (bool) $this->option('purge'), 'pending_cancelled' => $cancelled]);

        // Status goes to stderr so stdout stays pipeable: php artisan sentinel:revoke 1 | ssh root@host bash
        $this->getOutput()->getErrorStyle()->writeln("Sentinel is now blocked from {$machine->name} ({$cancelled} pending action(s) cancelled). Run the script below as root on the machine to remove access there too.");

        $this->output->write($script->render($machine, (bool) $this->option('purge')));

        return self::SUCCESS;
    }
}
