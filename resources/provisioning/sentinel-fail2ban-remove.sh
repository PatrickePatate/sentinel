#!/usr/bin/env bash
# Managed by Sentinel. Removes ONLY files named sentinel-<name> in filter.d and jail.d.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 1 ]] || { echo "usage: $0 <name>" >&2; exit 2; }
name=$1
[[ $name =~ ^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$ ]] || { echo "invalid name" >&2; exit 2; }

rm -f "/etc/fail2ban/jail.d/sentinel-$name.local" "/etc/fail2ban/filter.d/sentinel-$name.conf"
fail2ban-client reload >/dev/null 2>&1 || true
echo "removed sentinel-$name"
