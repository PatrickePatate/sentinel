<?php

namespace App\Ssh\Fake;

use App\Models\Machine;
use App\Ssh\CommandResult;
use App\Ssh\HostKeyFingerprint;
use App\Ssh\SshTransport;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Dev stand-in for PhpseclibTransport: answers the exact commands of the tool
 * and action catalogs like a small Ubuntu server would, with state that lives
 * across calls (a restart really fixes a service, a vacuum really frees disk).
 * It keeps the production contract: host key must be pinned and match.
 */
class FakeTransport implements SshTransport
{
    public function run(Machine $machine, string $command, int $timeoutSeconds): CommandResult
    {
        $machine->assertActive();

        $state = $this->state($machine);

        if ($state['unreachable']) {
            throw new RuntimeException("ssh: connect to host {$machine->host} port {$machine->port}: Connection timed out");
        }

        $this->verifyHostKey($machine);
        $this->simulateLatency();

        [$result, $state] = $this->dispatch($command, $state);
        Cache::forever($this->key($machine), $state);

        return $result;
    }

    public static function fingerprintFor(Machine $machine): string
    {
        return HostKeyFingerprint::of("fake-host-key:{$machine->host}:{$machine->port}");
    }

    public static function reset(Machine $machine): void
    {
        Cache::forget(self::keyFor($machine));
    }

    /** @return array<string, mixed> */
    public function state(Machine $machine): array
    {
        return Cache::rememberForever($this->key($machine), fn () => FakeMachineProfile::initialState(FakeMachineProfile::forHost($machine->host), $machine->host));
    }

    private function key(Machine $machine): string
    {
        return self::keyFor($machine);
    }

    private static function keyFor(Machine $machine): string
    {
        return "sentinel.fake.{$machine->id}.{$machine->host}";
    }

    private function verifyHostKey(Machine $machine): void
    {
        $fingerprint = self::fingerprintFor($machine);

        if ($machine->host_key_fingerprint === null) {
            throw new RuntimeException("Host key of {$machine->name} is not pinned yet ({$fingerprint}). Pin it before use.");
        }

        if (! hash_equals($machine->host_key_fingerprint, $fingerprint)) {
            throw new RuntimeException("Host key mismatch for {$machine->name}: refusing to connect.");
        }
    }

    private function simulateLatency(): void
    {
        if ($ms = (int) config('sentinel.fake.latency_ms')) {
            usleep(random_int((int) ($ms / 2), $ms) * 1000);
        }
    }

    /** @return array{0: CommandResult, 1: array<string, mixed>} */
    private function dispatch(string $command, array $s): array
    {
        $ok = fn (string $out, int $code = 0) => new CommandResult($out, $code);

        // Actions run through `sudo -n` in production; the simulated machine accepts them as root.
        $command = preg_replace('/(^|\|\| |\| )sudo -n /', '$1', $command);

        if (preg_match("/^systemctl reload -- '([^']+)' 2>&1$/", $command, $m)) {
            $svc = $s['services'][$m[1]] ?? null;

            return $svc && $svc['state'] === 'active'
                ? [$ok(''), $s]
                : [$ok("Failed to reload {$m[1]}.service: Unit {$m[1]}.service is not active, cannot reload.\n", 1), $s];
        }

        if (preg_match("/^systemctl reset-failed -- '([^']+)' 2>&1$/", $command, $m)) {
            if (isset($s['services'][$m[1]]) && $s['services'][$m[1]]['state'] === 'failed') {
                $s['services'][$m[1]]['state'] = 'inactive';
                $s['services'][$m[1]]['failure'] = '';
            }

            return [$ok(''), $s];
        }

        if (preg_match("#^/usr/local/sbin/sentinel-upgrade-package '([^']+)' 2>&1$#", $command, $m)) {
            $before = count($s['upgradable']);
            $s['upgradable'] = array_values(array_filter($s['upgradable'], fn ($p) => $p[0] !== $m[1]));

            return $before === count($s['upgradable'])
                ? [$ok("package {$m[1]} is not installed or already up to date\n", 4), $s]
                : [$ok("Reading package lists...\nThe following packages will be upgraded:\n  {$m[1]}\n1 upgraded, 0 newly installed, 0 to remove.\n"), $s];
        }

        if ($command === 'certbot renew 2>&1') {
            return [$ok("Processing /etc/letsencrypt/renewal/example.org.conf\nCert not yet due for renewal\nNo renewals were attempted.\n"), $s];
        }

        if (preg_match("#^/usr/local/sbin/sentinel-fail2ban-unban '([^']+)' '([^']+)' 2>&1$#", $command, $m)) {
            return [$ok("1\n"), $s];
        }

        if (str_starts_with($command, 'timeout 15 fail2ban-regex')) {
            return [$ok("Running tests\n=============\n\nResults\n=======\n\nFailregex: 12 total\nLines: 4210 lines, 0 ignored, 12 matched, 4198 missed\n"), $s];
        }

        if (preg_match("#sentinel-fail2ban-filter '([^']+)' 2>&1$#", $command, $m)) {
            $s['f2b'][$m[1]]['filter'] = true;

            return [$ok("filter sentinel-{$m[1]} installed\n"), $s];
        }

        if (preg_match("#^/usr/local/sbin/sentinel-fail2ban-jail '([^']+)' '([^']+)' #", $command, $m)) {
            $s['f2b'][$m[1]]['jail'] = true;

            return [$ok("jail sentinel-{$m[1]} installed and loaded\n"), $s];
        }

        if (preg_match("#^/usr/local/sbin/sentinel-fail2ban-remove '([^']+)' 2>&1$#", $command, $m)) {
            unset($s['f2b'][$m[1]]);

            return [$ok("removed sentinel-{$m[1]}\n"), $s];
        }

        if (preg_match("/^systemctl restart -- '([^']+)' 2>&1$/", $command, $m)) {
            return $this->restart($m[1], $s);
        }

        if (preg_match("/^systemctl status --no-pager --lines=20 -- '([^']+)' 2>&1$/", $command, $m)) {
            return [$this->status($m[1], $s), $s];
        }

        if (str_starts_with($command, 'journalctl --vacuum-time')) {
            $freed = round($s['journal_mb'] * 0.92, 1);
            $s['journal_mb'] = (int) round($s['journal_mb'] - $freed);

            return [$ok("Vacuuming done, freed {$freed}M of archived journals from /var/log/journal/".md5($s['hostname']).".\n"), $s];
        }

        if ($command === 'apt-get clean 2>&1') {
            $s['apt_cache_mb'] = 0;

            return [$ok(''), $s];
        }

        $out = match (true) {
            str_starts_with($command, 'cat /etc/os-release') => $this->systemInfo($s),
            str_starts_with($command, 'df -hT') => $this->df($s),
            $command === 'free -m' => $this->free($s),
            str_starts_with($command, 'ps aux') => $this->ps($s),
            str_starts_with($command, 'systemctl --failed') => $this->failed($s),
            str_starts_with($command, 'ss -tulpn') => $this->ports($s),
            str_starts_with($command, 'apt list --upgradable') => $this->upgradable($s),
            str_starts_with($command, 'apt-get -s upgrade') => $this->securityUpdates($s),
            str_contains($command, '/var/run/reboot-required') => $s['reboot_required'] ? "*** System restart required ***\n" : "no reboot required\n",
            str_starts_with($command, 'sshd -T') => collect($s['sshd'])->map(fn ($v, $k) => "$k $v")->implode("\n")."\n",
            str_starts_with($command, 'journalctl -u ssh') => $this->authFailures($s),
            str_starts_with($command, 'who; last') => $this->users($s),
            str_starts_with($command, 'ufw status') => $this->firewall($s),
            default => null,
        };

        return $out === null
            ? [$ok("bash: line 1: {$this->firstWord($command)}: command not found\n", 127), $s]
            : [$ok($out, $this->exitCode($command, $out)), $s];
    }

    private function firstWord(string $command): string
    {
        return strtok($command, ' ') ?: $command;
    }

    private function exitCode(string $command, string $out): int
    {
        // Mirrors `grep` in the pipeline: no match means exit 1.
        return $out === '' && (str_contains($command, 'grep')) ? 1 : 0;
    }

    private function restart(string $name, array $s): array
    {
        $svc = $s['services'][$name] ?? null;

        if (! $svc) {
            return [new CommandResult("Failed to restart {$name}.service: Unit {$name}.service not found.\n", 5), $s];
        }

        if ($svc['broken']) {
            $s['services'][$name]['since'] = time();

            return [new CommandResult("Job for {$name}.service failed because the control process exited with error code.\nSee \"systemctl status {$name}.service\" and \"journalctl -xeu {$name}.service\" for details.\n", 1), $s];
        }

        $s['services'][$name] = array_replace($svc, ['state' => 'active', 'failure' => '', 'since' => time(), 'pid' => random_int(2000, 30000), 'mem_mb' => max($svc['mem_mb'], 40)]);

        return [new CommandResult('', 0), $s];
    }

    private function status(string $name, array $s): CommandResult
    {
        $svc = $s['services'][$name] ?? null;

        if (! $svc) {
            return new CommandResult("Unit {$name}.service could not be found.\n", 4);
        }

        $since = date('D Y-m-d H:i:s \U\T\C', $svc['since']);
        $ago = $this->human(time() - $svc['since']);
        $dot = $svc['state'] === 'active' ? '●' : '×';
        $head = "{$dot} {$name}.service - {$svc['description']}\n     Loaded: loaded (/usr/lib/systemd/system/{$name}.service; enabled; preset: enabled)\n";

        if ($svc['state'] === 'active') {
            return new CommandResult($head."     Active: active (running) since {$since}; {$ago} ago\n   Main PID: {$svc['pid']} ({$name})\n      Tasks: 4 (limit: 9484)\n     Memory: {$svc['mem_mb']}.2M\n\n{$this->hostLine($s)} systemd[1]: Started {$name}.service - {$svc['description']}.\n", 0);
        }

        $log = $svc['broken']
            ? "{$this->hostLine($s)} {$name}[1893]: *** FATAL CONFIG FILE ERROR (Redis 7.0.15) ***\n{$this->hostLine($s)} {$name}[1893]: Bad directive or wrong number of arguments: 'maxmemory-polciy allkeys-lru'\n{$this->hostLine($s)} systemd[1]: {$name}.service: Main process exited, code=exited, status=1/FAILURE\n{$this->hostLine($s)} systemd[1]: {$name}.service: Failed with result 'exit-code'.\n"
            : "{$this->hostLine($s)} kernel: Out of memory: Killed process 1310 ({$name}) total-vm:1240512kB\n{$this->hostLine($s)} systemd[1]: {$name}.service: A process of this unit has been killed by the OOM killer.\n{$this->hostLine($s)} systemd[1]: {$name}.service: Failed with result 'oom-kill'.\n";

        $label = $svc['state'] === 'failed' ? "failed (Result: {$this->result($svc)})" : 'inactive (dead)';

        return new CommandResult($head."     Active: {$label} since {$since}; {$ago} ago\n\n".($svc['state'] === 'failed' ? $log : ''), 3);
    }

    private function result(array $svc): string
    {
        return $svc['broken'] ? 'exit-code' : 'oom-kill';
    }

    private function hostLine(array $s): string
    {
        return date('M d H:i:s').' '.$s['hostname'];
    }

    private function human(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 => intdiv($seconds, 86400).' days',
            $seconds >= 3600 => intdiv($seconds, 3600).'h',
            $seconds >= 60 => intdiv($seconds, 60).'min',
            default => $seconds.'s',
        };
    }

    private function systemInfo(array $s): string
    {
        $up = time() - $s['booted_at'];
        $days = intdiv($up, 86400);
        $hours = intdiv($up % 86400, 3600);
        $mins = intdiv($up % 3600, 60);
        [$l1, $l5, $l15] = $this->load($s);
        $load = $s['profile'] === 'healthy' ? '' : '';

        return <<<TXT
        PRETTY_NAME="Ubuntu 24.04.1 LTS"
        NAME="Ubuntu"
        VERSION_ID="24.04"
        VERSION="24.04.1 LTS (Noble Numbat)"
        VERSION_CODENAME=noble
        ID=ubuntu
        ID_LIKE=debian
        HOME_URL="https://www.ubuntu.com/"
        Linux {$s['hostname']} {$s['kernel']} #45-Ubuntu SMP PREEMPT_DYNAMIC Fri Aug 30 12:02:04 UTC 2024 x86_64 x86_64 x86_64 GNU/Linux
         {$this->clock()} up {$days} days, {$hours}:{$this->pad($mins)},  2 users,  load average: {$l1}, {$l5}, {$l15}{$load}

        TXT;
    }

    private function clock(): string
    {
        return date('H:i:s');
    }

    private function pad(int $n): string
    {
        return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function load(array $s): array
    {
        $base = match ($s['profile']) {
            'degraded' => 3.4, 'attacked' => 2.1, 'disk-full' => 0.9, default => 0.35,
        };
        $jitter = fn (float $x) => number_format(max(0.01, $x + (random_int(-10, 10) / 100)), 2);

        return [$jitter($base), $jitter($base * 0.9), $jitter($base * 0.8)];
    }

    private function usedGb(array $s): float
    {
        return $s['disk_base_gb'] + ($s['logs_mb'] + $s['journal_mb'] + $s['apt_cache_mb']) / 1024;
    }

    private function df(array $s): string
    {
        $total = $s['disk_total_gb'];
        $used = min($this->usedGb($s), $total);
        $pct = (int) ceil($used / $total * 100);
        $fmt = fn (float $g) => $g >= 10 ? round($g).'G' : number_format($g, 1).'G';

        return "Filesystem     Type   Size  Used Avail Use% Mounted on\n"
            .sprintf("/dev/sda1      ext4   %4s  %4s  %4s  %3d%% /\n", $fmt($total), $fmt($used), $fmt($total - $used), $pct)
            ."/dev/sda15     vfat   105M  6.1M   99M   6% /boot/efi\n";
    }

    private function free(array $s): string
    {
        $active = collect($s['services'])->where('state', 'active')->sum('mem_mb');
        $used = 540 + $active + ($s['profile'] === 'degraded' ? 4200 : 0);
        $total = $s['mem_total_mb'];
        $used = min($used, $total - 120);
        $cache = 1100;
        $swapUsed = $s['profile'] === 'degraded' ? 1980 : 12;

        return "               total        used        free      shared  buff/cache   available\n"
            .sprintf("Mem:     %10d  %10d  %10d  %10d  %10d  %10d\n", $total, $used, $total - $used - $cache, 24, $cache, $total - $used)
            .sprintf("Swap:    %10d  %10d  %10d\n", $s['swap_total_mb'], $swapUsed, $s['swap_total_mb'] - $swapUsed);
    }

    private function ps(array $s): string
    {
        $rows = [['root', 1, 0.0, 0.1, 'systemd'], ['root', 2, 0.0, 0.0, 'kthreadd']];

        foreach ($s['services'] as $name => $svc) {
            if ($svc['state'] !== 'active') {
                continue;
            }
            $cpu = match ($name) {
                'mysql' => $s['profile'] === 'disk-full' ? 14.2 : 2.4,
                'php8.3-fpm' => 1.8, 'nginx' => 0.6, default => 0.1,
            };
            $user = match ($name) {
                'mysql' => 'mysql', 'redis-server' => 'redis', 'nginx', 'php8.3-fpm' => 'www-data', default => 'root',
            };
            $rows[] = [$user, $svc['pid'], $cpu, round($svc['mem_mb'] / $s['mem_total_mb'] * 100, 1), $this->processCommand($name)];
        }

        if ($s['profile'] === 'attacked') {
            $rows[] = ['www-data', 28841, 71.3, 3.2, '/tmp/.x/kdevtmpfsi'];
            $rows[] = ['root', 29010, 12.5, 0.4, 'sshd: root [priv]'];
        }

        usort($rows, fn ($a, $b) => $b[2] <=> $a[2]);

        $lines = ['USER         PID %CPU %MEM    VSZ   RSS TTY      STAT START   TIME COMMAND'];
        foreach (array_slice($rows, 0, 15) as [$user, $pid, $cpu, $mem, $cmd]) {
            $lines[] = sprintf('%-10s %5d %4.1f %4.1f %6d %5d ?        Ss   Sep17   %d:%02d %s', $user, $pid, $cpu, $mem, 12000 + $pid % 900000, 3000 + (int) ($mem * 400), intdiv((int) ($cpu * 30), 60), (int) ($cpu * 30) % 60, $cmd);
        }

        return implode("\n", $lines)."\n";
    }

    private function processCommand(string $name): string
    {
        return match ($name) {
            'mysql' => '/usr/sbin/mysqld',
            'nginx' => 'nginx: worker process',
            'php8.3-fpm' => 'php-fpm: pool www',
            'redis-server' => '/usr/bin/redis-server 127.0.0.1:6379',
            'ssh' => 'sshd: /usr/sbin/sshd -D [listener] 0 of 10-100 startups',
            'cron' => '/usr/sbin/cron -f -P',
            'fail2ban' => '/usr/bin/python3 /usr/bin/fail2ban-server -xf start',
            default => $name,
        };
    }

    private function failed(array $s): string
    {
        $failed = collect($s['services'])->where('state', 'failed');

        if ($failed->isEmpty()) {
            return "0 loaded units listed.\n";
        }

        $lines = ['  UNIT                     LOAD   ACTIVE SUB    DESCRIPTION'];
        foreach ($failed as $name => $svc) {
            $lines[] = sprintf('● %-24s loaded failed failed %s', $name.'.service', $svc['description']);
        }

        return implode("\n", $lines)."\n\nLegend: LOAD   → Reflects whether the unit definition was properly loaded.\n        ACTIVE → The high-level unit activation state, i.e. generalization of SUB.\n        SUB    → The low-level unit activation state, values depend on unit type.\n\n{$failed->count()} loaded units listed.\n";
    }

    private function ports(array $s): string
    {
        $lines = ['Netid State  Recv-Q Send-Q Local Address:Port Peer Address:Port Process'];
        $lines[] = 'udp   UNCONN 0      0      127.0.0.54:53        0.0.0.0:*    users:(("systemd-resolve",pid=612,fd=16))';

        foreach ($s['services'] as $name => $svc) {
            if ($svc['state'] !== 'active' || ! $svc['port']) {
                continue;
            }
            $bind = in_array($name, ['mysql', 'redis-server'], true) ? '127.0.0.1' : '0.0.0.0';
            if ($s['profile'] === 'attacked' && $name === 'redis-server') {
                $bind = '0.0.0.0';
            }
            $proc = explode(' ', $this->processCommand($name))[0];
            $proc = basename($name === 'nginx' ? 'nginx' : ($name === 'ssh' ? 'sshd' : $proc));
            $lines[] = sprintf('tcp   LISTEN 0      511    %s:%d %s:*    users:(("%s",pid=%d,fd=6))', $bind, $svc['port'], $bind === '0.0.0.0' ? '0.0.0.0' : '0.0.0.0', $proc, $svc['pid']);
            if ($name === 'nginx') {
                $lines[] = sprintf('tcp   LISTEN 0      511    0.0.0.0:443 0.0.0.0:*    users:(("nginx",pid=%d,fd=7))', $svc['pid']);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function upgradable(array $s): string
    {
        $lines = ['Listing...'];
        foreach ($s['upgradable'] as [$pkg, $new, $old, $suites]) {
            $lines[] = "{$pkg}/{$suites} {$new} amd64 [upgradable from: {$old}]";
        }

        return implode("\n", $lines)."\n";
    }

    private function securityUpdates(array $s): string
    {
        $lines = [];
        foreach ($s['upgradable'] as [$pkg, $new, , $suites]) {
            if (str_contains($suites, 'security')) {
                $lines[] = "Inst {$pkg} [{$new}] ({$new} Ubuntu:24.04/noble-security [amd64])";
            }
        }

        return $lines ? implode("\n", $lines)."\n" : '';
    }

    private function authFailures(array $s): string
    {
        $ips = $s['failing_ips'];
        $count = min(50, $s['auth_failures']);
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $ip = $ips[$i % count($ips)];
            $user = $s['profile'] === 'attacked' ? ['root', 'admin', 'ubuntu', 'test', 'oracle'][$i % 5] : 'deploy';
            $t = date('M d H:i:s', time() - ($count - $i) * ($s['profile'] === 'attacked' ? 37 : 5400));
            $lines[] = "{$t} {$s['hostname']} sshd[".(20000 + $i * 7).']: Failed password for '.($user === 'root' ? '' : 'invalid user ')."{$user} from {$ip} port ".(30000 + $i * 13).' ssh2';
        }

        return $lines ? implode("\n", $lines)."\n" : '';
    }

    private function users(array $s): string
    {
        $ip = '198.51.100.23';

        return 'deploy   pts/0        '.date('Y-m-d H:i', time() - 3600)." ({$ip})\n"
            ."deploy   pts/0        {$ip}    ".date('D M j H:i', time() - 3600)."   still logged in\n"
            ."deploy   pts/1        {$ip}    ".date('D M j H:i', time() - 86400).' - '.date('H:i', time() - 82000)."  (01:13)\n"
            ."reboot   system boot  {$s['kernel']} ".date('D M j H:i', $s['booted_at'])."   still running\n\n"
            .'wtmp begins '.date('D M j H:i:s Y', $s['booted_at'] - 86400 * 30)."\n";
    }

    private function firewall(array $s): string
    {
        if (! $s['ufw_active']) {
            return "Status: inactive\n";
        }

        return "Status: active\nLogging: on (low)\nDefault: deny (incoming), allow (outgoing), disabled (routed)\nNew profiles: skip\n\nTo                         Action      From\n--                         ------      ----\n22/tcp                     ALLOW IN    Anywhere\n80/tcp                     ALLOW IN    Anywhere\n443/tcp                    ALLOW IN    Anywhere\n22/tcp (v6)                ALLOW IN    Anywhere (v6)\n80/tcp (v6)                ALLOW IN    Anywhere (v6)\n443/tcp (v6)               ALLOW IN    Anywhere (v6)\n";
    }
}
