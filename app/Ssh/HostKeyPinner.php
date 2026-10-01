<?php

namespace App\Ssh;

use App\Models\Machine;
use Throwable;

/** The two ways a host key gets pinned: a fingerprint a human verified, or one the provisioning script reported over TLS. */
class HostKeyPinner
{
    public const PINNED = 'pinned';

    public const MISMATCH = 'mismatch';

    public const UNREACHABLE = 'unreachable';

    public function __construct(private AuditTrail $audit) {}

    /**
     * Pins the key the server presents now, but only if it is one of the fingerprints the machine itself reported.
     * A machine presents one host key per connection (the type depends on negotiation), so any of its keys will do.
     *
     * @return array{0: string, 1: string|null} status, fingerprint presented by the server
     */
    public function pinIfReported(Machine $machine, string $source = 'provision_callback'): array
    {
        return $this->pinIfIn($machine, $machine->host_keys_reported ?? [], $source);
    }

    /** @return array{0: string, 1: string|null} */
    public function pinVerified(Machine $machine, string $fingerprint, string $source = 'dashboard'): array
    {
        return $this->pinIfIn($machine, [$fingerprint], $source);
    }

    /** @param  list<string>  $trusted */
    private function pinIfIn(Machine $machine, array $trusted, string $source): array
    {
        try {
            $presented = HostKeyFingerprint::fetch($machine);
        } catch (Throwable $e) {
            report($e);

            return [self::UNREACHABLE, null];
        }

        if ($presented === null) {
            return [self::UNREACHABLE, null];
        }

        foreach ($trusted as $fingerprint) {
            if (HostKeyFingerprint::normalize((string) $fingerprint) === HostKeyFingerprint::normalize($presented)) {
                $machine->update(['host_key_fingerprint' => $presented]);
                $this->audit->record($machine, null, 'host_key_pinned', 'host_key_pinned', ['fingerprint' => $presented, 'source' => $source]);

                return [self::PINNED, $presented];
            }
        }

        return [self::MISMATCH, $presented];
    }
}
