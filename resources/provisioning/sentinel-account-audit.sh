#!/usr/bin/env bash
# Managed by Sentinel. Read-only: lists who can log in and with which keys. Changes nothing, takes no argument.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 0 ]] || { echo "usage: $0" >&2; exit 2; }

echo "== accounts with a login shell (user uid shell last-password-change)"
while IFS=: read -r name _ uid _ _ home shell; do
    case $shell in */nologin|*/false|*/sync|*/halt|*/shutdown|'') continue ;; esac
    changed=$(chage -l "$name" 2>/dev/null | awk -F': ' '/^Last password change/ {print $2}') || changed=
    echo "$name $uid $shell ${changed:-unknown}"
done < /etc/passwd

echo "== uid 0 accounts"
awk -F: '$3 == 0 {print $1}' /etc/passwd

echo "== sudo-capable groups"
for group in sudo wheel admin; do
    line=$(getent group "$group") || continue
    members=$(cut -d: -f4 <<<"$line")
    echo "$group: ${members:-none}"
done

echo "== sudoers drop-ins"
ls -l /etc/sudoers.d 2>/dev/null | tail -n +2 | awk '{print $6, $7, $8, $9}' || echo "unreadable"

echo "== authorized SSH keys (user: fingerprint comment, file modified)"
while IFS=: read -r name _ uid _ _ home shell; do
    for file in "$home/.ssh/authorized_keys" "$home/.ssh/authorized_keys2"; do
        [[ -f $file && ! -L $file ]] || continue
        echo "$name: $file modified $(date -r "$file" '+%Y-%m-%d %H:%M')"
        # One fingerprint per key; never print the keys themselves.
        while IFS= read -r line; do
            [[ -z $line || $line == \#* ]] && continue
            fp=$(ssh-keygen -lf /dev/stdin <<<"$line" 2>/dev/null | awk '{print $2, $NF}') || fp=
            echo "    ${fp:-unreadable key line}"
        done < "$file"
    done
done < /etc/passwd

echo "== /etc/passwd and /etc/shadow last modified"
date -r /etc/passwd '+passwd %Y-%m-%d %H:%M'
date -r /etc/shadow '+shadow %Y-%m-%d %H:%M'
