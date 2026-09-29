#!/usr/bin/env bash
# Managed by Sentinel. Writes jail.d/sentinel-<name>.local from validated parameters only.
# No `action`, `actionban`, `ignorecommand` or any free-form key can ever be produced.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 6 ]] || { echo "usage: $0 <name> <filter> <log> <maxretry> <findtime> <bantime>" >&2; exit 2; }
name=$1 filter=$2 log=$3 maxretry=$4 findtime=$5 bantime=$6
slug='^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$'
[[ $name =~ $slug && $filter =~ $slug ]] || { echo "invalid name or filter" >&2; exit 2; }
[[ -f /etc/fail2ban/filter.d/sentinel-$filter.conf ]] || { echo "filter sentinel-$filter does not exist" >&2; exit 4; }

case "$log" in
__LOG_CASES__
    *) echo "unknown log key" >&2; exit 2 ;;
esac

between() { [[ $1 =~ ^[0-9]+$ ]] && (( $1 >= $2 && $1 <= $3 )); }
between "$maxretry" 3 20 || { echo "maxretry must be 3-20" >&2; exit 2; }
between "$findtime" 60 86400 || { echo "findtime must be 60-86400" >&2; exit 2; }
between "$bantime" 60 604800 || { echo "bantime must be 60-604800" >&2; exit 2; }

dir=/etc/fail2ban/jail.d
target=$dir/sentinel-$name.local
backup=$(mktemp)
tmp=$(mktemp "$dir/.sentinel-XXXXXX")
trap 'rm -f "$tmp" "$backup"' EXIT
[[ -f $target ]] && cp -p "$target" "$backup" && had_previous=1 || had_previous=0

cat >"$tmp" <<JAIL
# Managed by Sentinel - do not edit by hand
[sentinel-$name]
enabled = true
filter = sentinel-$filter
logpath = $logpath
maxretry = $maxretry
findtime = $findtime
bantime = $bantime
ignoreip = 127.0.0.1/8 ::1 __IGNOREIP__
JAIL
chmod 0644 "$tmp"
mv -f "$tmp" "$target"

rollback() {
    if (( had_previous )); then cp -p "$backup" "$target"; else rm -f "$target"; fi
}
fail2ban-client -t >/dev/null 2>&1 || { rollback; echo "fail2ban config test failed, rolled back" >&2; exit 5; }
fail2ban-client reload >/dev/null 2>&1 || { rollback; fail2ban-client reload >/dev/null 2>&1 || true; echo "reload failed, rolled back" >&2; exit 5; }
echo "jail sentinel-$name installed and loaded"
