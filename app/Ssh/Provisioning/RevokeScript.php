<?php

namespace App\Ssh\Provisioning;

use App\Models\Machine;

/**
 * Renders the bash script that removes Sentinel's access from a machine: the counterpart of
 * ProvisionScript. Ordered so that the door closes first (key, sudo), then live sessions die.
 */
class RevokeScript
{
    public function render(Machine $machine, bool $purge = false): string
    {
        $user = $machine->username;
        SudoersBuilder::assertValidUser($user);

        $purgeBlock = $purge ? <<<'BASH'

# 6. Purge: remove custom fail2ban filters/jails created by Sentinel, then the user and its home
rm -f /etc/fail2ban/jail.d/sentinel-*.local /etc/fail2ban/filter.d/sentinel-*.conf
command -v fail2ban-client >/dev/null && fail2ban-client reload >/dev/null 2>&1 || true
rm -f /var/log/sentinel-sudo.log
BASH : '';

        $delete = $purge ? "userdel -r {$user} 2>/dev/null || true" : "echo \"User {$user} kept (locked, no key). Use --purge to delete it.\"";

        return <<<BASH
#!/usr/bin/env bash
# Sentinel access revocation for "{$machine->name}" - generated, review before running as root.
# Closes the door first (SSH key, sudo rights, wrappers), then ends live sessions and locks "{$user}".
set -uo pipefail

[[ \$EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }

# 1. SSH key: no new login is possible from here on
home=\$(getent passwd {$user} | cut -d: -f6 || true)
if [[ -n "\$home" && -d "\$home/.ssh" ]]; then
    rm -f "\$home/.ssh/authorized_keys"
fi

# 2. Privileges: sudo policy and root wrappers
rm -f /etc/sudoers.d/sentinel /etc/sudoers.d/.sentinel-*
rm -f /usr/local/sbin/sentinel-*

# 3. Live sessions and processes of the user
if id -u {$user} >/dev/null 2>&1; then
    loginctl terminate-user {$user} 2>/dev/null || true
    pkill -KILL -u {$user} 2>/dev/null || true
fi

# 4. Lock the account
if id -u {$user} >/dev/null 2>&1; then
    passwd -l {$user} >/dev/null 2>&1 || true
    usermod --expiredate 1 --shell /usr/sbin/nologin {$user} 2>/dev/null || true
fi

# 5. Optional deletion
{$delete}
{$purgeBlock}

echo "Sentinel access revoked on this machine."
if id -u {$user} >/dev/null 2>&1; then echo "Remaining sudo rights of {$user}:"; sudo -l -U {$user} 2>&1 | head -5; fi

BASH;
    }
}
