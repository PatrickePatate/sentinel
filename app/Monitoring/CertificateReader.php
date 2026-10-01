<?php

namespace App\Monitoring;

use Carbon\CarbonImmutable;
use Throwable;

/** Reads when the TLS certificate a host presents expires (the chain is not verified: expiry is the question here). */
class CertificateReader
{
    public function expiry(string $host, int $port = 443): ?CarbonImmutable
    {
        try {
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $error, 8, STREAM_CLIENT_CONNECT, $context);

            if (! $socket) {
                return null;
            }

            $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            fclose($socket);
            $validTo = $cert ? (openssl_x509_parse($cert)['validTo_time_t'] ?? null) : null;

            return $validTo ? CarbonImmutable::createFromTimestamp($validTo) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
