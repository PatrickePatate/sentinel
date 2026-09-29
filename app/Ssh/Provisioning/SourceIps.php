<?php

namespace App\Ssh\Provisioning;

use InvalidArgumentException;

/**
 * Addresses Sentinel connects from (SENTINEL_SOURCE_IPS, comma separated IPv4/IPv6, optionally CIDR).
 * Used to restrict the deployed SSH key (`from=`) and to whitelist Sentinel in fail2ban.
 */
class SourceIps
{
    /** @return list<string> */
    public static function configured(): array
    {
        $ips = array_values(array_unique(array_filter(array_map('trim', (array) config('sentinel.provisioning.source_ips')))));

        foreach ($ips as $ip) {
            if (! self::valid($ip)) {
                throw new InvalidArgumentException("SENTINEL_SOURCE_IPS contains an invalid entry [{$ip}]: use IPv4/IPv6 addresses or CIDR ranges, comma separated.");
            }
        }

        return $ips;
    }

    private static function valid(string $entry): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $max;
    }
}
