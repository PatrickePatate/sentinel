#!/usr/bin/env bash
# Managed by Sentinel. Opens or closes a time-boxed root lease for an unguarded chat session: while it is open the
# calling account may run any command through sudo. The lease removes itself after __UNGUARDED_TTL__ minutes (a systemd
# timer on this machine, so it ends even if Sentinel is gone). Fails closed: without systemd-run no lease is opened.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

ttl=__UNGUARDED_TTL__
file=/etc/sudoers.d/sentinel-unguarded
unit=sentinel-unguarded-expire
user=${SUDO_USER:-}

[[ $# -eq 1 ]] || { echo "usage: $0 on|off" >&2; exit 2; }
[[ $EUID -eq 0 ]] || { echo "must run as root" >&2; exit 2; }
[[ $user =~ ^[a-z_][a-z0-9_-]{0,31}$ && $user != root ]] || { echo "must be called through sudo by the Sentinel account" >&2; exit 2; }

stop_timer() { systemctl stop "$unit.timer" "$unit.service" 2>/dev/null || true; systemctl reset-failed "$unit.service" 2>/dev/null || true; }

case $1 in
    on)
        command -v systemd-run >/dev/null || { echo "systemd-run is missing: refusing to open a lease that could not expire" >&2; exit 4; }
        [[ $ttl =~ ^[0-9]{1,4}$ ]] && (( ttl >= 1 )) || { echo "invalid lease length" >&2; exit 4; }

        tmp=$(mktemp /etc/sudoers.d/.sentinel-unguarded.XXXXXX)
        trap 'rm -f "$tmp"' EXIT
        printf '# Managed by Sentinel: temporary unguarded session, removed automatically.\n%s ALL=(ALL) NOPASSWD: ALL\n' "$user" > "$tmp"
        chmod 0440 "$tmp"
        visudo -cf "$tmp" >/dev/null || { echo "visudo rejected the lease" >&2; exit 5; }

        # The expiry goes first: a lease that cannot be scheduled to end is never installed.
        stop_timer
        systemd-run --quiet --unit="$unit" --on-active="${ttl}m" /bin/rm -f -- "$file" || { echo "could not schedule the end of the lease" >&2; exit 5; }
        mv -f "$tmp" "$file"
        echo "unguarded session open for ${ttl} minutes"
        ;;
    off)
        stop_timer
        rm -f -- "$file"
        echo "unguarded session closed"
        ;;
    *)
        echo "usage: $0 on|off" >&2; exit 2
        ;;
esac
