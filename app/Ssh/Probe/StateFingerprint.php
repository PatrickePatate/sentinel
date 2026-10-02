<?php

namespace App\Ssh\Probe;

use App\Models\Machine;
use App\Ssh\SshTransport;

/**
 * A hash of what a security audit looks at and that should not change on its own: packages waiting for security
 * updates, failed units, listening sockets, SSH settings, accounts, scheduled jobs, the kernel. Volatile numbers
 * (load, failed login counts, bans) are left out on purpose, and disk usage only counts in steps of 10%.
 * Same hash as the last AI audit: nothing it would comment on has changed.
 */
class StateFingerprint
{
    public function __construct(private SshTransport $transport) {}

    public function command(): string
    {
        $sudo = config('sentinel.actions.use_sudo') ? 'sudo -n ' : '';

        return <<<SH
# sentinel-fingerprint
uname -r; cat /etc/os-release 2>/dev/null | grep -E '^(ID|VERSION_ID)='
echo "== failed"; systemctl list-units --state=failed --no-legend --plain --no-pager 2>/dev/null | awk '{print \$1}' | sort
echo "== listening"; ss -Htuln 2>/dev/null | awk '{print \$1, \$5}' | sort -u
echo "== sshd"; {$sudo}sshd -T 2>/dev/null | grep -Ei '^(permitrootlogin|passwordauthentication|pubkeyauthentication|port|x11forwarding|maxauthtries) ' | sort
echo "== security"; apt-get -s upgrade 2>/dev/null | grep -c '^Inst .*securi' || true
echo "== reboot"; test -f /var/run/reboot-required && echo yes || echo no
echo "== accounts"; awk -F: '\$7 !~ /(nologin|false|sync|halt|shutdown)\$/ {print \$1, \$3, \$7}' /etc/passwd | sort; getent group sudo wheel admin 2>/dev/null | sort
echo "== cron"; md5sum /etc/crontab /etc/cron.d/* 2>/dev/null | sort
echo "== disk"; df -P / | awk 'NR==2 {print int(\$5 / 10)}'
SH;
    }

    public function compute(Machine $machine): ?string
    {
        $result = $this->transport->run($machine, $this->command(), 40);

        // An empty answer proves nothing: never let it match a previous one.
        return trim($result->output) === '' ? null : hash('sha256', trim($result->output));
    }
}
