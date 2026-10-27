#!/usr/bin/env bash
# Managed by Sentinel. Runs the root side of an unguarded chat session: a time-boxed lease, and the commands a human
# approved one by one during it.
#
#   sentinel-unguarded on <token> <signature>    open the lease the signed token describes
#   sentinel-unguarded run <token> <signature>   run the one command the signed token carries, as root
#   sentinel-unguarded check <token> <signature> verify a lease token, open nothing (does not need root)
#   sentinel-unguarded off                        close the lease
#
# Nothing here grants sudo: the Sentinel account never gets a root shell, even during a session. Every token is signed
# by the Sentinel key below, for this machine's host key, used once and within minutes, so holding the Sentinel SSH
# key is not enough to run anything. A command runs only with its own token, for the lease that is open, while it is
# open: the lease ends at its end time and with any reboot, checked on every command, without timers or Sentinel.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

max_minutes=__UNGUARDED_TTL__
signing_key='__SIGNING_KEY__'
namespace=sentinel-unguarded
state=/var/lib/sentinel-unguarded
lease=$state/lease

die() { echo "sentinel-unguarded: $2" >&2; exit "$1"; }

# Exit codes: 2 usage, 3 token not signed by Sentinel, 4 token refused (expired, replayed, other machine...), 5 system.
# Sets $token to the verified token line, and $now.
verify_signed() {
    local token_b64=$1 sig_b64=$2 work
    [[ $token_b64 =~ ^[A-Za-z0-9+/=]{1,8192}$ && $sig_b64 =~ ^[A-Za-z0-9+/=]{1,4096}$ ]] || die 2 "invalid token encoding"
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
    now=$(date +%s)
}

# The checks every token shares: issued for this machine, to be used now.
check_window() {
    local host=$1 not_after=$2 key ours=0
    (( now <= not_after )) || die 4 "the token expired (check the clocks of Sentinel and this machine)"
    (( not_after - now <= 600 )) || die 4 "the token is valid for too long (check the clocks of Sentinel and this machine)"

    for key in /etc/ssh/ssh_host_*_key.pub; do
        [[ -r $key ]] || continue
        [[ $(ssh-keygen -lf "$key" 2>/dev/null | awk '{print $2}') == "$host" ]] && ours=1
    done
    (( ours )) || die 4 "the token was issued for another machine"
}

verify_lease() {
    verify_signed "$1" "$2"
    [[ $token =~ ^SENTINEL-LEASE\ 2\ host=(SHA256:[A-Za-z0-9+/]{43})\ session=([0-9a-f]{32})\ not_after=([0-9]{10})\ minutes=([0-9]{1,4})$ ]] \
        || die 4 "unsupported token"
    session=${BASH_REMATCH[2]} minutes=$((10#${BASH_REMATCH[4]}))
    check_window "${BASH_REMATCH[1]}" "${BASH_REMATCH[3]}"
    (( minutes >= 1 && minutes <= max_minutes )) || die 4 "this machine allows leases of at most ${max_minutes} minutes: update its client bundle"
    not_after=${BASH_REMATCH[3]}
}

# A token is used once. Its id is kept until the token expired, which is all the time it could be replayed.
use_once() {
    touch "$state/used"
    awk -v now="$now" '$2 >= now' "$state/used" > "$state/used.new" && mv -f "$state/used.new" "$state/used"
    ! grep -q "^$1 " "$state/used" || die 4 "this token was already used"
    printf '%s %s\n' "$1" "$2" >> "$state/used"
}

# What earlier versions of this wrapper left: a sudoers grant and the timers that removed it.
legacy_cleanup() {
    rm -f -- /etc/sudoers.d/sentinel-unguarded
    if command -v systemctl >/dev/null; then
        systemctl disable --now sentinel-unguarded-end.timer >/dev/null 2>&1 || true
        systemctl stop sentinel-unguarded-expire.timer sentinel-unguarded-expire.service >/dev/null 2>&1 || true
        if [[ -e /etc/systemd/system/sentinel-unguarded-end.timer || -e /etc/systemd/system/sentinel-unguarded-end.service ]]; then
            rm -f -- /etc/systemd/system/sentinel-unguarded-end.timer /etc/systemd/system/sentinel-unguarded-end.service
            systemctl daemon-reload >/dev/null 2>&1 || true
        fi
    fi
    return 0
}

[[ $# -ge 1 ]] || die 2 "usage: $0 on|run|check|off"

case $1 in
    check)
        [[ $# -eq 3 ]] || die 2 "usage: $0 check <token> <signature>"
        verify_lease "$2" "$3"
        echo "token valid for a ${minutes}-minute lease"
        exit 0
        ;;
    on|run|off) ;;
    *) die 2 "usage: $0 on|run|check|off" ;;
esac

[[ $EUID -eq 0 ]] || die 2 "must run as root"
install -d -m 0700 -o root -g root "$state"
exec 9>"$state/lock"
flock -w 10 9 || die 5 "the lease is busy"

case $1 in
    on)
        [[ $# -eq 3 ]] || die 2 "usage: $0 on <token> <signature>"
        verify_lease "$2" "$3"
        use_once "$session" "$not_after"
        legacy_cleanup
        printf '%s %s %s\n' "$session" "$(( now + minutes * 60 ))" "$(cat /proc/sys/kernel/random/boot_id)" > "$lease.new"
        mv -f "$lease.new" "$lease"
        echo "unguarded session open for ${minutes} minutes"
        ;;
    run)
        [[ $# -eq 3 ]] || die 2 "usage: $0 run <token> <signature>"
        verify_signed "$2" "$3"
        [[ $token =~ ^SENTINEL-RUN\ 1\ host=(SHA256:[A-Za-z0-9+/]{43})\ session=([0-9a-f]{32})\ nonce=([0-9a-f]{32})\ not_after=([0-9]{10})\ command=([A-Za-z0-9+/=]{4,3000})$ ]] \
            || die 4 "unsupported token"
        host=${BASH_REMATCH[1]} session=${BASH_REMATCH[2]} nonce=${BASH_REMATCH[3]} not_after=${BASH_REMATCH[4]} command_b64=${BASH_REMATCH[5]}
        check_window "$host" "$not_after"

        [[ -f $lease ]] || die 4 "no unguarded session is open"
        read -r open_session ends boot_id < "$lease" || die 4 "no unguarded session is open"
        [[ $open_session == "$session" ]] || die 4 "the command belongs to another unguarded session"
        [[ $ends =~ ^[0-9]{1,12}$ ]] && (( now < ends )) || { rm -f -- "$lease"; die 4 "the unguarded session expired"; }
        [[ $boot_id == "$(cat /proc/sys/kernel/random/boot_id)" ]] || { rm -f -- "$lease"; die 4 "the unguarded session ended with the reboot"; }
        use_once "$nonce" "$not_after"

        command=$(base64 -d <<< "$command_b64" 2>/dev/null) || die 2 "invalid command encoding"
        [[ -n $command ]] || die 2 "empty command"
        exec 9>&-
        exec bash -c "$command"
        ;;
    off)
        [[ $# -eq 1 ]] || die 2 "usage: $0 off"
        rm -f -- "$lease"
        legacy_cleanup
        echo "unguarded session closed"
        ;;
esac
