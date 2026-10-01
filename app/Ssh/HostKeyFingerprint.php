<?php

namespace App\Ssh;

use App\Models\Machine;

class HostKeyFingerprint
{
    public static function fetch(Machine $machine): ?string
    {
        if (config('sentinel.transport') === 'fake') {
            return Fake\FakeTransport::fingerprintFor($machine);
        }

        $key = SshConnection::open($machine)->getServerPublicHostKey();

        return $key ? self::of($key) : null;
    }

    /**
     * The same value as `ssh-keygen -lf` and `ssh-keyscan`: SHA256 of the raw key blob.
     * Accepts the "<type> <base64>" form phpseclib and authorized_keys use.
     */
    public static function of(string $publicHostKey): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', self::blob($publicHostKey), true)), '=');
    }

    /**
     * Fingerprints computed before the blob was hashed (they covered the "<type> <base64>" text, so they never matched
     * what the server prints). Kept so that existing pins keep working until they are upgraded.
     */
    public static function legacy(string $publicHostKey): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $publicHostKey, true)), '=');
    }

    /** Whether a fingerprint typed by a human is the one of this key (case of the prefix and padding do not matter). */
    public static function matches(string $pinned, string $publicHostKey): bool
    {
        return hash_equals(self::normalize($pinned), self::normalize(self::of($publicHostKey)));
    }

    public static function normalize(string $fingerprint): string
    {
        $fingerprint = trim($fingerprint);
        $fingerprint = preg_replace('/^sha256:/i', 'SHA256:', $fingerprint);

        return rtrim($fingerprint, '=');
    }

    private static function blob(string $publicHostKey): string
    {
        if (preg_match('/^[a-z0-9@._-]+ ([A-Za-z0-9+\/]+=*)$/', trim($publicHostKey), $m) && ($blob = base64_decode($m[1], true)) !== false) {
            return $blob;
        }

        return $publicHostKey;
    }
}
