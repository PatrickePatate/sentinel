#!/usr/bin/env bash
# Managed by Sentinel. Starts ONE service that is down (failed or inactive). It never touches a running service, so it cannot cause downtime,
# and it refuses to start a web server whose configuration does not pass its own test (a start would fail, or serve a broken site).
set -uo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 1 ]] || { echo "usage: $0 <unit>" >&2; exit 2; }
unit=${1%.service}

allowed=0
for pattern in __RECOVERABLE__; do
    # shellcheck disable=SC2053
    [[ $unit == $pattern ]] && allowed=1
done
(( allowed )) || { echo "unit $unit is not on the recoverable allowlist" >&2; exit 2; }
[[ $unit =~ ^[A-Za-z0-9@._-]{1,64}$ && $unit != -* ]] || { echo "invalid unit name" >&2; exit 2; }

systemctl cat "$unit.service" >/dev/null 2>&1 || { echo "unit $unit.service does not exist on this machine" >&2; exit 2; }

state=$(systemctl is-active "$unit.service" 2>/dev/null)
case $state in
    active) echo "$unit is already running: nothing to do"; exit 0 ;;
    activating|reloading|deactivating) echo "$unit is $state: not interfering, check again in a moment"; exit 0 ;;
esac

case $unit in
    nginx) kind=nginx ;;
    apache2|httpd) kind=apache2 ;;
    php*-fpm) kind=php-fpm ;;
    *) kind= ;;
esac
if [[ -n $kind ]] && [[ -x /usr/local/sbin/sentinel-web-config ]]; then
    if ! out=$(/usr/local/sbin/sentinel-web-config test "$kind" 2>&1); then
        echo "$unit is $state but its configuration is invalid: not starting it."
        echo "$out"
        echo "hint: sentinel-web-config rollback $kind restores the last configuration that passed the test"
        exit 3
    fi
fi

echo "$unit was $state, starting it"
systemctl reset-failed "$unit.service" 2>/dev/null
systemctl start "$unit.service" 2>&1
sleep 2
state=$(systemctl is-active "$unit.service" 2>/dev/null)
echo "$unit is now: $state"
if [[ $state != active ]]; then
    journalctl --no-pager -n 15 -u "$unit.service" 2>&1
    exit 4
fi
