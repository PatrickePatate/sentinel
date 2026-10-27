<?php

namespace App\Ssh;

use App\Models\Machine;
use RuntimeException;
use Throwable;

/**
 * An unguarded chat session: a time-boxed root lease on the machine, so that commands a human approved one by one
 * can run as root. The lease commands come from the catalog like every other command, and it also ends on its own
 * on the machine (see resources/provisioning/sentinel-unguarded.sh).
 */
class UnguardedSession
{
    public function __construct(
        private ActionCatalog $catalog,
        private SshTransport $transport,
        private AuditTrail $audit,
    ) {}

    /** @throws RuntimeException when the lease could not be opened: nothing is then considered open. */
    public function open(Machine $machine): void
    {
        $machine->assertActive();
        $minutes = max(1, (int) config('sentinel.unguarded.lease_minutes'));

        try {
            $result = $this->transport->run($machine, $this->catalog->get('unguarded_open')->command([]), SafeExecutor::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            $this->audit->record($machine, null, 'unguarded_failed', 'unguarded session', ['error' => $e->getMessage()]);

            throw new RuntimeException('Could not reach the machine to open the session (details are in the audit log).');
        }

        if ($result->exitCode !== 0) {
            $this->audit->record($machine, null, 'unguarded_failed', 'unguarded session', ['exit_code' => $result->exitCode, 'output_excerpt' => mb_strcut($result->output, 0, 500)]);

            throw new RuntimeException('The machine refused the lease: '.trim(mb_strcut($result->output, 0, 300)).' Update the client bundle of this machine if the wrapper is missing.');
        }

        $machine->forceFill(['unguarded_until' => now()->addMinutes($minutes)])->save();
        $this->audit->record($machine, null, 'unguarded_opened', 'unguarded session', ['minutes' => $minutes]);
    }

    /** Ends the session. It is over for Sentinel either way; false means the machine could not be told (the lease then ends by its own timer). */
    public function close(Machine $machine, string $reason = 'ended by a user'): bool
    {
        $machine->forceFill(['unguarded_until' => null])->save();

        try {
            $result = $this->transport->run($machine, $this->catalog->get('unguarded_close')->command([]), SafeExecutor::TIMEOUT_SECONDS);
            $closed = $result->exitCode === 0;
        } catch (Throwable $e) {
            $closed = false;
        }

        $this->audit->record($machine, null, $closed ? 'unguarded_closed' : 'unguarded_close_failed', 'unguarded session', ['reason' => $reason]);

        return $closed;
    }
}
