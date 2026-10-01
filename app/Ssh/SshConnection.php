<?php

namespace App\Ssh;

use App\Models\Machine;
use phpseclib3\Net\SSH2;

class SshConnection
{
    public static function open(Machine $machine, int $connectTimeout = 10): SSH2
    {
        return new SSH2(self::socketHost($machine->host), $machine->port, $connectTimeout);
    }

    /** fsockopen() needs IPv6 literals in brackets, otherwise it cuts the address at the first colon. */
    public static function socketHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
    }
}
