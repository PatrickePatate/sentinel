#!/usr/bin/env bash
# Managed by Sentinel. Hardens sshd through one drop-in file; validates its own input and refuses changes that could lock admins out.
# Root SSH key logins are always kept: PermitRootLogin is only ever set to prohibit-password (keys only), never to no.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 2 ]] || { echo "usage: $0 <prohibit-password|keep> <no|keep>" >&2; exit 2; }
root_login=$1
password_auth=$2
[[ $root_login =~ ^(prohibit-password|keep)$ ]] || { echo "invalid root login value (root key logins are always kept)" >&2; exit 2; }
[[ $password_auth =~ ^(no|keep)$ ]] || { echo "invalid password auth value" >&2; exit 2; }
[[ $root_login != keep || $password_auth != keep ]] || { echo "nothing to change" >&2; exit 2; }

dropin=/etc/ssh/sshd_config.d/00-sentinel-hardening.conf
agent=${SUDO_USER:-}

# sshd uses the first value it reads: the drop-in only wins if sshd_config includes the directory before its own settings.
grep -qE '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' /etc/ssh/sshd_config \
    || { echo "sshd_config does not include /etc/ssh/sshd_config.d/*.conf, refusing" >&2; exit 3; }

has_keys() { local home; home=$(getent passwd "$1" | cut -d: -f6); [[ -n $home && -s $home/.ssh/authorized_keys ]]; }

# Someone other than Sentinel must still be able to log in with a key and become root afterwards.
admin_with_key=0
for member in $(getent group sudo | cut -d: -f4 | tr ',' ' ') $(getent group wheel | cut -d: -f4 | tr ',' ' '); do
    [[ $member != "$agent" ]] && has_keys "$member" && admin_with_key=1
done
has_keys root && admin_with_key=1
if [[ $password_auth == no || $root_login != keep ]]; then
    if (( ! admin_with_key )); then
        echo "no administrator other than $agent (root, or a sudo/wheel member) has an SSH key: refusing, it could lock you out" >&2
        exit 4
    fi
fi

backup=
[[ -f $dropin ]] && { backup=$(mktemp); cp -p "$dropin" "$backup"; }
restore() { if [[ -n $backup ]]; then mv -f "$backup" "$dropin"; else rm -f "$dropin"; fi; }

{
    echo "# Managed by Sentinel (harden_ssh). Remove this file and reload ssh to undo."
    [[ $root_login != keep ]] && echo "PermitRootLogin $root_login"
    if [[ $password_auth == no ]]; then
        echo "PasswordAuthentication no"
        echo "KbdInteractiveAuthentication no"
    fi
} > "$dropin"
chmod 0644 "$dropin"

sshd -t || { restore; echo "sshd rejected the configuration, reverted" >&2; exit 5; }

# OpenSSH before 9.7 prints "without-password" for the value it documents as "prohibit-password".
effective=$(sshd -T 2>/dev/null | sed 's/^permitrootlogin without-password$/permitrootlogin prohibit-password/')
if [[ $root_login != keep ]] && ! grep -qx "permitrootlogin $root_login" <<<"$effective"; then
    restore; echo "another sshd setting overrides PermitRootLogin, reverted" >&2; exit 6
fi
if [[ $password_auth == no ]] && ! grep -qx "passwordauthentication no" <<<"$effective"; then
    restore; echo "another sshd setting overrides PasswordAuthentication, reverted" >&2; exit 6
fi

[[ -n $backup ]] && rm -f "$backup"
# Reloading keeps existing sessions open.
systemctl reload ssh 2>/dev/null || systemctl reload sshd
echo "sshd hardened:"
grep -E '^(permitrootlogin|passwordauthentication|kbdinteractiveauthentication) ' <<<"$effective"
