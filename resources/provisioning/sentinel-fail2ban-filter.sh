#!/usr/bin/env bash
# Managed by Sentinel. Installs filter.d/sentinel-<name>.conf built ONLY from failregex lines
# read (base64) on stdin. Nothing else can be written: no includes, no actions, no commands.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 1 ]] || { echo "usage: base64 payload on stdin; $0 <name>" >&2; exit 2; }
name=$1
[[ $name =~ ^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$ ]] || { echo "invalid name" >&2; exit 2; }

payload=$(head -c 9000)
[[ ${#payload} -le 8000 ]] || { echo "payload too large" >&2; exit 2; }
decoded=$(printf '%s' "$payload" | base64 -d) || { echo "invalid payload" >&2; exit 2; }
mapfile -t lines <<<"$decoded"
(( ${#lines[@]} >= 1 && ${#lines[@]} <= 5 )) || { echo "1 to 5 failregex lines expected" >&2; exit 2; }

dir=/etc/fail2ban/filter.d
target=$dir/sentinel-$name.conf
tmp=$(mktemp "$dir/.sentinel-XXXXXX")
trap 'rm -f "$tmp"' EXIT

{
    echo "# Managed by Sentinel - do not edit by hand"
    echo "[Definition]"
    first=1
    for line in "${lines[@]}"; do
        [[ ${#line} -ge 1 && ${#line} -le 500 ]] || { echo "invalid regex length" >&2; exit 2; }
        [[ $line =~ ^[[:graph:]][[:print:]]*$ ]] || { echo "regex must be printable ASCII without leading space" >&2; exit 2; }
        [[ $line == *'<HOST>'* || $line == *'<ADDR>'* ]] || { echo "regex must contain <HOST>" >&2; exit 2; }
        if (( first )); then echo "failregex = $line"; first=0; else echo "            $line"; fi
    done
} >"$tmp"

fail2ban-regex /dev/null "$tmp" >/dev/null 2>&1 || { echo "fail2ban rejected the filter (syntax error)" >&2; exit 5; }

chmod 0644 "$tmp"
mv -f "$tmp" "$target"
trap - EXIT
echo "filter sentinel-$name installed"
