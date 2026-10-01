<?php

namespace App\Ssh;

use App\Models\Machine;
use App\Models\PendingAction;

/** Local kill switch of a machine (the dashboard and `sentinel:revoke` share it). */
class AccessControl
{
    public function __construct(private AuditTrail $audit) {}

    /** Blocks Sentinel from the machine at once and cancels what was waiting for approval. @return int cancelled actions */
    public function revoke(Machine $machine, bool $purge = false): int
    {
        // "Approve always" grants go too: lifting the revocation later must not bring back unattended actions.
        $machine->forceFill(['revoked_at' => $machine->revoked_at ?? now(), 'autonomy_enabled' => false, 'trusted_actions' => null])->save();

        $cancelled = PendingAction::where('machine_id', $machine->id)->where('status', 'pending')
            ->update(['status' => 'rejected', 'decided_at' => now()]);

        $this->audit->record($machine, null, 'machine_revoked', 'access revoked', ['purge' => $purge, 'pending_cancelled' => $cancelled]);

        return $cancelled;
    }

    public function lift(Machine $machine): void
    {
        $machine->forceFill(['revoked_at' => null])->save();
        $this->audit->record($machine, null, 'machine_restored', 'revocation lifted');
    }
}
