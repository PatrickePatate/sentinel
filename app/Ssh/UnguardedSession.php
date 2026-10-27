<?php

namespace App\Ssh;

use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\Provisioning\BundleSigner;
use App\Support\Realtime;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * An unguarded chat session: a time-boxed root lease on the machine, so that commands a human approved one by one
 * can run as root. The lease commands come from the catalog like every other command, and it also ends on its own
 * on the machine (see resources/provisioning/sentinel-unguarded.sh).
 *
 * The machine only opens a lease for a token signed by Sentinel's signing key, bound to its host key, used once and
 * within TOKEN_SECONDS: the SSH key of the Sentinel account (or a database dump) is not enough to become root.
 */
class UnguardedSession
{
    public const NAMESPACE = 'sentinel-unguarded';

    /** How long a lease token may be presented to the machine; the lease itself lasts lease_minutes. */
    public const TOKEN_SECONDS = 120;

    public function __construct(
        private ActionCatalog $catalog,
        private SshTransport $transport,
        private AuditTrail $audit,
        private BundleSigner $signer,
    ) {}

    /** @throws RuntimeException when the lease could not be opened: nothing is then considered open. */
    public function open(Machine $machine): void
    {
        $machine->assertActive();
        $minutes = max(1, (int) config('sentinel.unguarded.lease_minutes'));

        if ($machine->host_key_fingerprint === null) {
            throw new RuntimeException('Pin the host key of this machine first: the lease is issued for that key only.');
        }

        try {
            $result = $this->transport->run($machine, $this->catalog->get('unguarded_open')->command($this->token($machine, $minutes)), SafeExecutor::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            $this->audit->record($machine, null, 'unguarded_failed', 'unguarded session', ['error' => $e->getMessage()]);

            throw new RuntimeException('Could not reach the machine to open the session (details are in the audit log).');
        }

        if ($result->exitCode !== 0) {
            $this->audit->record($machine, null, 'unguarded_failed', 'unguarded session', ['exit_code' => $result->exitCode, 'output_excerpt' => mb_strcut($result->output, 0, 500)]);

            throw new RuntimeException('The machine refused the lease: '.trim(mb_strcut($result->output, 0, 300)).' Update the client bundle of this machine if the wrapper is missing.');
        }

        $session = (string) Str::uuid();
        $machine->forceFill(['unguarded_until' => now()->addMinutes($minutes), 'unguarded_session' => $session])->save();
        // Commands left from an earlier session (it expired without being ended) never come back to life in this one.
        $this->dropCommands($machine);
        $this->audit->record($machine, null, 'unguarded_opened', 'unguarded session', ['minutes' => $minutes, 'session' => $session]);
    }

    /**
     * The signed lease request: one line naming the machine by its pinned host key, a single-use nonce, the time the
     * token must be used by, and the lease length (the machine also caps it).
     *
     * @return array{token: string, signature: string}
     */
    private function token(Machine $machine, int $minutes): array
    {
        $host = HostKeyFingerprint::normalize($machine->host_key_fingerprint);
        $token = 'SENTINEL-LEASE 1 host='.$host.' nonce='.bin2hex(random_bytes(16)).' not_after='.(now()->getTimestamp() + self::TOKEN_SECONDS).' minutes='.$minutes;

        return ['token' => base64_encode($token), 'signature' => base64_encode($this->signer->sign($token, self::NAMESPACE))];
    }

    /** Rejects the raw commands still waiting on this machine that do not belong to its open session. */
    private function dropCommands(Machine $machine): void
    {
        $dropped = PendingAction::where('machine_id', $machine->id)->where('action', 'run_command')->where('status', 'pending')
            ->when($machine->unguarded_session, fn ($q, $session) => $q->where(fn ($q) => $q->whereNull('unguarded_session')->orWhere('unguarded_session', '!=', $session)))
            ->update(['status' => 'rejected', 'decided_at' => now(), 'output' => 'The unguarded session it was proposed in ended.']);

        if ($dropped > 0) {
            Realtime::push('actions');
        }
    }

    /** Ends the session. It is over for Sentinel either way; false means the machine could not be told (the lease then ends by its own timer). */
    public function close(Machine $machine, string $reason = 'ended by a user'): bool
    {
        $machine->forceFill(['unguarded_until' => null, 'unguarded_session' => null])->save();
        $this->dropCommands($machine);

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
