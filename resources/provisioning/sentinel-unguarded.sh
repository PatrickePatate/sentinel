#!/usr/bin/env bash
# Managed by Sentinel. Opens or closes a time-boxed root lease for an unguarded chat session: while it is open the
# calling account may run any command through sudo.
#
#   sentinel-unguarded on <token> <signature>   open the lease the signed token describes
#   sentinel-unguarded check <token> <signature> verify a token, open nothing (does not need root)
#   sentinel-unguarded off                       close the lease
#   sentinel-unguarded expire                    close it if it ran out or the machine rebooted (run by systemd)
#
# Opening needs a token signed by the Sentinel key below, for this machine's host key, used once and within minutes:
# holding the Sentinel SSH key is not enough to become root. The lease ends on its own, even if Sentinel is gone and
# across reboots: sudo itself refuses it after its end time where it supports NOTAFTER, a persistent systemd timer
# removes it, and a reboot always ends it. Fails closed: if the end cannot be scheduled, no lease is opened.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

max_minutes=__UNGUARDED_TTL__
signing_key='__SIGNING_KEY__'
namespace=sentinel-unguarded
file=/etc/sudoers.d/sentinel-unguarded
state=/var/lib/sentinel-unguarded
unit=sentinel-unguarded-end
units=/etc/systemd/system

die() { echo "sentinel-unguarded: $2" >&2; exit "$1"; }

# Exit codes: 2 usage, 3 token not signed by Sentinel, 4 token refused (expired, replayed, other machine...), 5 system.
verify() {
    local token_b64=$1 sig_b64=$2 work
    [[ $token_b64 =~ ^[A-Za-z0-9+/=]{1,1024}$ && $sig_b64 =~ ^[A-Za-z0-9+/=]{1,4096}$ ]] || die 2 "invalid token encoding"
    command -v ssh-keygen >/dev/null || die 5 "ssh-keygen is required to verify the token"

    work=$(mktemp -d)
    base64 -d <<< "$token_b64" > "$work/token" 2>/dev/null || { rm -rf "$work"; die 2 "invalid token encoding"; }
    base64 -d <<< "$sig_b64" > "$work/sig" 2>/dev/null || { rm -rf "$work"; die 2 "invalid signature encoding"; }
    printf 'sentinel namespaces="%s" %s\n' "$namespace" "$signing_key" > "$work/allowed_signers"

    if ! ssh-keygen -Y verify -f "$work/allowed_signers" -I sentinel -n "$namespace" -s "$work/sig" < "$work/token" >/dev/null 2>&1; then
        rm -rf "$work"
        die 3 "the token is not signed by Sentinel"
    fi
    token=$(cat "$work/token")
    rm -rf "$work"

    [[ $token =~ ^SENTINEL-LEASE\ 1\ host=(SHA256:[A-Za-z0-9+/]{43})\ nonce=([0-9a-f]{32})\ not_after=([0-9]{10})\ minutes=([0-9]{1,4})$ ]] \
        || die 4 "unsupported token"
    host=${BASH_REMATCH[1]} nonce=${BASH_REMATCH[2]} not_after=${BASH_REMATCH[3]} minutes=$((10#${BASH_REMATCH[4]}))

    now=$(date +%s)
    (( now <= not_after )) || die 4 "the token expired (check the clocks of Sentinel and this machine)"
    (( not_after - now <= 600 )) || die 4 "the token is valid for too long (check the clocks of Sentinel and this machine)"
    (( minutes >= 1 && minutes <= max_minutes )) || die 4 "this machine allows leases of at most ${max_minutes} minutes: update its client bundle"

    local key ours=0
    for key in /etc/ssh/ssh_host_*_key.pub; do
        [[ -r $key ]] || continue
        [[ $(ssh-keygen -lf "$key" 2>/dev/null | awk '{print $2}') == "$host" ]] && ours=1
    done
    (( ours )) || die 4 "the token was issued for another machine"
}

# Removes the lease and everything that would end it. Safe to call when nothing is open.
end_lease() {
    rm -f -- "$file"
    systemctl disable --now "$unit.timer" >/dev/null 2>&1 || true
    # The transient timer of earlier versions of this wrapper.
    systemctl stop sentinel-unguarded-expire.timer sentinel-unguarded-expire.service >/dev/null 2>&1 || true
    rm -f -- "$units/$unit.timer" "$units/$unit.service" "$state/until" "$state/boot_id"
    systemctl daemon-reload >/dev/null 2>&1 || true
}

[[ $# -ge 1 ]] || die 2 "usage: $0 on|check|off|expire"

case $1 in
    check)
        [[ $# -eq 3 ]] || die 2 "usage: $0 check <token> <signature>"
        verify "$2" "$3"
        echo "token valid for a ${minutes}-minute lease"
        exit 0
        ;;
    on|off|expire) ;;
    *) die 2 "usage: $0 on|check|off|expire" ;;
esac

[[ $EUID -eq 0 ]] || die 2 "must run as root"

case $1 in
    on)
        [[ $# -eq 3 ]] || die 2 "usage: $0 on <token> <signature>"
        user=${SUDO_USER:-}
        [[ $user =~ ^[a-z_][a-z0-9_-]{0,31}$ && $user != root ]] || die 2 "must be called through sudo by the Sentinel account"
        command -v systemctl >/dev/null && [[ -d /run/systemd/system ]] || die 5 "systemd is required: refusing to open a lease that could not expire"
        verify "$2" "$3"

        install -d -m 0700 -o root -g root "$state"
        exec 9>"$state/lock"
        flock -w 10 9 || die 5 "another lease is being opened"

        # A token is used once. Nonces are kept until their token expired, which is all the time it could be replayed.
        touch "$state/nonces"
        awk -v now="$now" '$2 >= now' "$state/nonces" > "$state/nonces.new" && mv -f "$state/nonces.new" "$state/nonces"
        ! grep -q "^$nonce " "$state/nonces" || die 4 "this token was already used"
        printf '%s %s\n' "$nonce" "$not_after" >> "$state/nonces"

        ends=$(( now + minutes * 60 ))
        tmp=$(mktemp /etc/sudoers.d/.sentinel-unguarded.XXXXXX)
        trap 'rm -f "$tmp"' EXIT
        chmod 0440 "$tmp"
        # sudo refuses a dated rule after its end time on its own; sudo-rs does not support NOTAFTER yet, and then
        # only the timer and the reboot check below end the lease.
        printf '# Managed by Sentinel: temporary unguarded session, removed automatically.\n%s ALL=(ALL) NOTAFTER=%s NOPASSWD: ALL\n' \
            "$user" "$(date -u -d "@$ends" +%Y%m%d%H%M%SZ)" > "$tmp"
        dated=yes
        if ! visudo -cf "$tmp" >/dev/null 2>&1; then
            printf '# Managed by Sentinel: temporary unguarded session, removed automatically.\n%s ALL=(ALL) NOPASSWD: ALL\n' "$user" > "$tmp"
            visudo -cf "$tmp" >/dev/null || die 5 "visudo rejected the lease"
            dated=no
        fi

        # The end goes first: a lease that cannot be scheduled to end is never installed. The timer is persistent (it
        # fires at boot if its time passed while the machine was down) and also fires at every boot, where a lease
        # from before the reboot is always removed. If the wrapper is gone by then, the unit removes the lease itself.
        end_lease
        install -d -m 0700 -o root -g root "$state"
        printf '%s\n' "$ends" > "$state/until"
        cat /proc/sys/kernel/random/boot_id > "$state/boot_id"
        cat > "$units/$unit.service" <<UNIT
[Unit]
Description=End the Sentinel unguarded session lease

[Service]
Type=oneshot
ExecStart=/bin/sh -c '/usr/local/sbin/sentinel-unguarded expire || rm -f $file'
UNIT
        cat > "$units/$unit.timer" <<UNIT
[Unit]
Description=End the Sentinel unguarded session lease

[Timer]
OnCalendar=$(date -u -d "@$ends" '+%Y-%m-%d %H:%M:%S') UTC
OnBootSec=1s
Persistent=true
AccuracySec=1s

[Install]
WantedBy=timers.target
UNIT
        chmod 0644 "$units/$unit.service" "$units/$unit.timer"
        if ! { systemctl daemon-reload && systemctl enable --now "$unit.timer" >/dev/null 2>&1; }; then
            end_lease
            die 5 "could not schedule the end of the lease"
        fi

        mv -f "$tmp" "$file"
        echo "unguarded session open for ${minutes} minutes (until ${ends}, enforced by sudo: ${dated})"
        ;;
    off)
        [[ $# -eq 1 ]] || die 2 "usage: $0 off"
        end_lease
        echo "unguarded session closed"
        ;;
    expire)
        [[ $# -eq 1 ]] || die 2 "usage: $0 expire"
        ends=$(cat "$state/until" 2>/dev/null || true)
        boot_id=$(cat "$state/boot_id" 2>/dev/null || true)
        if [[ $ends =~ ^[0-9]{1,12}$ ]] && (( $(date +%s) < ends )) && [[ $boot_id == "$(cat /proc/sys/kernel/random/boot_id)" ]]; then
            echo "unguarded session still open"
        else
            end_lease
            echo "unguarded session ended"
        fi
        ;;
esac
