<?php

namespace App\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;

/**
 * Time-based one-time passwords (RFC 6238: HMAC-SHA1, 30 second steps, 6 digits), the codes authenticator apps show.
 * A code is accepted one step early or late (clock drift) and only once.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32(random_bytes(20));
    }

    public function code(string $secret, int $timestamp): string
    {
        $counter = pack('N*', 0, intdiv($timestamp, 30));
        $hash = hash_hmac('sha1', $counter, $this->decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    /** @param string $replayKey Identifies whose code this is: a step already used under this key is refused. */
    public function verify(string $secret, string $code, string $replayKey, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        $timestamp ??= time();

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        foreach ([0, -1, 1] as $drift) {
            $at = $timestamp + $drift * 30;

            if (hash_equals($this->code($secret, $at), $code)) {
                $step = intdiv($at, 30);
                $last = (int) Cache::get("totp-step:{$replayKey}", 0);

                if ($step <= $last) {
                    return false;
                }

                Cache::put("totp-step:{$replayKey}", $step, now()->addMinutes(5));

                return true;
            }
        }

        return false;
    }

    public function uri(string $secret, string $account, string $issuer = 'Sentinel'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'digits' => 6, 'period' => 30]);
    }

    public function qrSvg(string $uri): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($uri);
    }

    /** @return list<string> */
    public function recoveryCodes(int $count = 8): array
    {
        return array_map(fn () => implode('-', str_split(strtolower($this->base32(random_bytes(5))), 4)), range(1, $count));
    }

    private function base32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    private function decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }
}
