<?php

namespace App\Ssh\Fake;

use InvalidArgumentException;

/**
 * Initial state of a simulated server. Each profile is a story the agent can
 * discover: pick the one matching the first label of the machine host
 * (e.g. "disk-full.fake.test"), otherwise one is chosen from the host hash.
 */
final class FakeMachineProfile
{
    public const NAMES = ['healthy', 'disk-full', 'degraded', 'attacked', 'unreachable'];

    /** @return array<string, mixed> */
    public static function initialState(string $profile, string $host): array
    {
        if (! in_array($profile, self::NAMES, true)) {
            throw new InvalidArgumentException("Unknown fake profile: {$profile}");
        }

        $state = [
            'profile' => $profile,
            'hostname' => explode('.', $host)[0],
            'booted_at' => now()->subDays(12)->subHours(3)->getTimestamp(),
            'kernel' => '6.8.0-45-generic',
            'cpu_count' => 4,
            'mem_total_mb' => 7938,
            'swap_total_mb' => 2047,
            'disk_total_gb' => 80.0,
            'disk_base_gb' => 21.4,
            'logs_mb' => 640,
            'journal_mb' => 310,
            'apt_cache_mb' => 420,
            'upgradable' => [
                ['curl', '8.5.0-2ubuntu10.5', '8.5.0-2ubuntu10.4', 'noble-updates,noble-security'],
                ['libssl3t64', '3.0.13-0ubuntu3.4', '3.0.13-0ubuntu3.3', 'noble-updates,noble-security'],
                ['tzdata', '2024b-0ubuntu0.24.04', '2024a-3ubuntu1.1', 'noble-updates'],
            ],
            'reboot_required' => false,
            'sshd' => ['permitrootlogin' => 'no', 'passwordauthentication' => 'no', 'pubkeyauthentication' => 'yes', 'port' => '22', 'x11forwarding' => 'no', 'maxauthtries' => '3'],
            'auth_failures' => 2,
            'failing_ips' => ['203.0.113.44'],
            'ufw_active' => true,
            'unreachable' => false,
            'services' => [
                'ssh' => self::service('active', 22, 'OpenBSD Secure Shell server', 812, 6),
                'nginx' => self::service('active', 80, 'A high performance web server and a reverse proxy server', 1204, 48),
                'php8.3-fpm' => self::service('active', null, 'The PHP 8.3 FastCGI Process Manager', 1310, 310),
                'mysql' => self::service('active', 3306, 'MySQL Community Server', 1420, 690),
                'redis-server' => self::service('active', 6379, 'Advanced key-value store', 1502, 42),
                'cron' => self::service('active', null, 'Regular background program processing daemon', 655, 3),
                'fail2ban' => self::service('active', null, 'Fail2Ban Service', 901, 38),
            ],
        ];

        return match ($profile) {
            'disk-full' => array_replace($state, [
                'disk_base_gb' => 44.0,
                'logs_mb' => 21_800,
                'journal_mb' => 3_900,
                'apt_cache_mb' => 1_650,
                'booted_at' => now()->subDays(94)->getTimestamp(),
            ]),
            'degraded' => self::degrade($state),
            'attacked' => self::attacked($state),
            'unreachable' => array_replace($state, ['unreachable' => true]),
            default => $state,
        };
    }

    public static function forHost(string $host): string
    {
        $label = explode('.', $host)[0];

        if (in_array($label, self::NAMES, true)) {
            return $label;
        }

        // Stable, but mostly healthy so a random fleet still looks plausible.
        $weighted = ['healthy', 'healthy', 'healthy', 'disk-full', 'degraded', 'attacked'];

        return $weighted[crc32($host) % count($weighted)];
    }

    private static function degrade(array $state): array
    {
        // php-fpm died on OOM and can be restarted; redis has a broken config and never comes back.
        $state['services']['php8.3-fpm'] = self::service('failed', null, 'The PHP 8.3 FastCGI Process Manager', 0, 0, 'Result: oom-kill');
        $state['services']['redis-server'] = self::service('failed', 6379, 'Advanced key-value store', 0, 0, 'Result: exit-code', broken: true);
        $state['reboot_required'] = true;
        $state['upgradable'][] = ['linux-image-generic', '6.8.0.48.50', '6.8.0.45.47', 'noble-updates,noble-security'];
        $state['apt_cache_mb'] = 900;

        return $state;
    }

    private static function attacked(array $state): array
    {
        $state['sshd'] = ['permitrootlogin' => 'yes', 'passwordauthentication' => 'yes', 'pubkeyauthentication' => 'yes', 'port' => '22', 'x11forwarding' => 'yes', 'maxauthtries' => '6'];
        $state['auth_failures'] = 412;
        $state['failing_ips'] = ['185.224.128.17', '45.155.205.233', '103.75.190.12', '91.240.118.50'];
        $state['services']['fail2ban'] = self::service('inactive', null, 'Fail2Ban Service', 0, 0);
        $state['ufw_active'] = false;

        return $state;
    }

    /** @return array<string, mixed> */
    private static function service(string $state, ?int $port, string $description, int $pid, int $memMb, string $failure = '', bool $broken = false): array
    {
        return compact('state', 'port', 'description', 'pid') + ['mem_mb' => $memMb, 'failure' => $failure, 'broken' => $broken, 'since' => now()->subHours(random_int(2, 200))->getTimestamp()];
    }
}
