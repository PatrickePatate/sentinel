<?php

namespace App\Ssh;

use App\Models\Machine;
use phpseclib3\Net\SSH2;

class HostKeyFingerprint
{
    public static function fetch(Machine $machine): ?string
    {
        $key = (new SSH2($machine->host, $machine->port, 10))->getServerPublicHostKey();

        return $key ? self::of($key) : null;
    }

    public static function of(string $publicHostKey): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $publicHostKey, true)), '=');
    }
}
