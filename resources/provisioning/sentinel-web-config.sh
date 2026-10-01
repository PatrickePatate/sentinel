#!/usr/bin/env bash
# Managed by Sentinel. Tests, safely reloads or rolls back the configuration of a web server (nginx, apache2, php-fpm).
#   test <svc>      run the service's own config test; when it passes, remember the configuration as "last known good"
#   reload <svc>    test, and only reload (never restart) when the test passes
#   rollback <svc>  when the CURRENT configuration fails its test, restore the last known good one (the broken one is kept aside)
set -uo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

STORE=/var/lib/sentinel/webconfig
[[ $# -eq 2 ]] || { echo "usage: $0 <test|reload|rollback> <nginx|apache2|php-fpm>" >&2; exit 2; }
action=$1 svc=$2
[[ $action =~ ^(test|reload|rollback)$ ]] || { echo "unknown action" >&2; exit 2; }

case $svc in
    nginx) dirs=(/etc/nginx); units=(nginx) ;;
    apache2) dirs=(/etc/apache2); units=(apache2) ;;
    php-fpm) dirs=(/etc/php); mapfile -t units < <(systemctl list-unit-files --no-legend 'php*-fpm.service' 2>/dev/null | awk '{sub(/\.service$/,"",$1); print $1}') ;;
    *) echo "unknown service: $svc" >&2; exit 2 ;;
esac

config_test() {
    case $svc in
        nginx) command -v nginx >/dev/null || { echo "nginx is not installed"; return 2; }; nginx -t 2>&1 ;;
        apache2) if command -v apache2ctl >/dev/null; then apache2ctl configtest 2>&1; else echo "apache2 is not installed"; return 2; fi ;;
        php-fpm)
            local bin found=0 rc=0
            for bin in /usr/sbin/php-fpm*; do
                [[ -x $bin ]] || continue
                found=1; echo "$bin:"; "$bin" -t 2>&1 || rc=1
            done
            (( found )) || { echo "php-fpm is not installed"; return 2; }
            return $rc ;;
    esac
}

snapshot() {
    install -d -m 0700 "$STORE" || return 1
    tar -C / -cpf "$STORE/$svc.tar.tmp" "${dirs[@]#/}" 2>/dev/null && mv -f "$STORE/$svc.tar.tmp" "$STORE/$svc.tar" && date -u +%FT%TZ > "$STORE/$svc.time"
}

reload_units() {
    local u rc=0
    for u in "${units[@]}"; do
        if systemctl is-active --quiet "$u"; then systemctl reload "$u" 2>&1 || rc=1; else systemctl start "$u" 2>&1 || rc=1; fi
    done
    return $rc
}

out=$(config_test); rc=$?

case $action in
    test)
        echo "$out"
        if (( rc == 0 )); then snapshot && echo "result: configuration OK (recorded as last known good)" || echo "result: configuration OK"; exit 0; fi
        (( rc == 2 )) && exit 2
        if [[ -s $STORE/$svc.tar ]]; then echo "result: configuration INVALID; a last known good copy from $(cat "$STORE/$svc.time" 2>/dev/null) exists (rollback possible)"; else echo "result: configuration INVALID; no known good copy to roll back to"; fi
        exit 1 ;;
    reload)
        if (( rc != 0 )); then echo "$out"; echo "configuration test failed: NOT reloading"; exit 3; fi
        snapshot
        reload_units && echo "$svc configuration OK and reloaded" || { echo "reload failed"; exit 4; } ;;
    rollback)
        if (( rc == 0 )); then echo "the current $svc configuration passes its test: nothing to roll back"; exit 0; fi
        (( rc == 2 )) && { echo "$out"; exit 2; }
        [[ -s $STORE/$svc.tar ]] || { echo "$out"; echo "no known good configuration was recorded for $svc: cannot roll back"; exit 3; }
        ts=$(date -u +%Y%m%dT%H%M%SZ)
        for d in "${dirs[@]}"; do
            [[ -e $d ]] && mv "$d" "$d.sentinel-broken-$ts"
        done
        if tar -C / -xpf "$STORE/$svc.tar" && out2=$(config_test); then
            echo "restored the configuration recorded on $(cat "$STORE/$svc.time" 2>/dev/null); the broken one is kept in ${dirs[0]}.sentinel-broken-$ts"
            echo "the error was:"; echo "$out"
            reload_units && echo "$svc is running with the restored configuration" || { echo "restored, but reloading failed"; exit 4; }
        else
            for d in "${dirs[@]}"; do
                rm -rf "$d"; [[ -e $d.sentinel-broken-$ts ]] && mv "$d.sentinel-broken-$ts" "$d"
            done
            echo "the recorded configuration does not pass the test either; the original was put back"; echo "${out2:-}"; exit 5
        fi ;;
esac
