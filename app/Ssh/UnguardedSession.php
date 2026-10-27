<?php

namespace App\Ssh;

use App\Models\Machine;
use App\Models\PendingAction;
use App\Ssh\Actions\UnguardedLeaseAction;
use App\Ssh\Provisioning\BundleSigner;
use App\Support\Realtime;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * An unguarded chat session: a time-boxed lease on the machine, during which commands a human approved one by one run
 * as root. The lease commands come from the catalog like every other command, and the lease ends on its own on the
 * machine, at its end time or with a reboot (see resources/provisioning/sentinel-unguarded.sh).
 *
 * Nothing grants the Sentinel account sudo rights: the machine opens a lease, and runs each command, only for a token
 * signed by Sentinel's signing key, bound to its host key and to the open lease, used once and within TOKEN_SECONDS.
 * The SSH key of the Sentinel account (or a database dump) is not enough to run anything as root.
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

        $session = (string) Str::uuid();
        $line = 'SENTINEL-LEASE 2 host='.$this->host($machine).' session='.self::wire($session).' not_after='.$this->notAfter().' minutes='.$minutes;

        try {
            $result = $this->transport->run($machine, $this->catalog->get('unguarded_open')->command($this->signed($line)), SafeExecutor::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            $this->audit->record($machine, null, 'unguarded_failed', 'unguarded session', ['error' => $e->getMessage()]);
            // The lease may have opened before the connection failed. Sentinel never signs a command for it (the
            // session is not recorded), so it is harmless, but it should not linger until its end time either.
            $this->tryToClose($machine);

            throw new RuntimeException('Could not reach the machine to open the session (details are in the audit log).');
        }

        if ($result->exitCode !== 0) {
            $this->audit->record($machine, null, 'unguarded_failed', 'unguarded session', ['exit_code' => $result->exitCode, 'output_excerpt' => mb_strcut($result->output, 0, 500)]);

            throw new RuntimeException('The machine refused the lease: '.trim(mb_strcut($result->output, 0, 300)).' Update the client bundle of this machine if the wrapper is missing.');
        }

        $machine->forceFill(['unguarded_until' => now()->addMinutes($minutes), 'unguarded_session' => $session])->save();
        // Commands left from an earlier session (it expired without being ended) never come back to life in this one.
        $this->dropCommands($machine);
        $this->audit->record($machine, null, 'unguarded_opened', 'unguarded session', ['minutes' => $minutes, 'session' => $session]);
    }

    /**
     * The machine command that runs one approved command as root: a token for this run only, bound to the open
     * session and carrying the command itself, so the machine runs exactly what was signed.
     *
     * @throws RuntimeException when no session is open: a command is never signed outside one.
     */
    public function runCommand(Machine $machine, string $command): string
    {
        if (! $machine->unguardedActive() || $machine->unguarded_session === null || $machine->host_key_fingerprint === null) {
            throw new RuntimeException('No unguarded session is open on this machine.');
        }

        $line = 'SENTINEL-RUN 1 host='.$this->host($machine).' session='.self::wire($machine->unguarded_session)
            .' nonce='.bin2hex(random_bytes(16)).' not_after='.$this->notAfter().' command='.base64_encode($command);
        $token = $this->signed($line);
        $lease = $this->catalog->get('unguarded_open');

        if (! $lease instanceof UnguardedLeaseAction) {
            throw new LogicException('unguarded_open must be the lease action.');
        }

        return $lease->signedCommand('run', $token['token'], $token['signature']);
    }

    /** @return array{token: string, signature: string} The token line and its signature, both base64 for the command line. */
    private function signed(string $line): array
    {
        return ['token' => base64_encode($line), 'signature' => base64_encode($this->signer->sign($line, self::NAMESPACE))];
    }

    private function host(Machine $machine): string
    {
        return HostKeyFingerprint::normalize($machine->host_key_fingerprint);
    }

    private function notAfter(): int
    {
        return now()->getTimestamp() + self::TOKEN_SECONDS;
    }

    /** The session id as the wrapper reads it: 32 hex digits. */
    private static function wire(string $session): string
    {
        return str_replace('-', '', $session);
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
        $closed = $this->tryToClose($machine);

        $this->audit->record($machine, null, $closed ? 'unguarded_closed' : 'unguarded_close_failed', 'unguarded session', ['reason' => $reason]);

        return $closed;
    }

    private function tryToClose(Machine $machine): bool
    {
        try {
            return $this->transport->run($machine, $this->catalog->get('unguarded_close')->command([]), SafeExecutor::TIMEOUT_SECONDS)->exitCode === 0;
        } catch (Throwable) {
            return false;
        }
    }
}
