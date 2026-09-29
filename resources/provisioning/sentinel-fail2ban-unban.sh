#!/usr/bin/env bash
# Managed by Sentinel. Unbans ONE ip from ONE existing jail; validates its own input.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 2 ]] || { echo "usage: $0 <jail> <ip>" >&2; exit 2; }
jail=$1
ip=$2
[[ $jail =~ ^[a-zA-Z0-9_-]{1,32}$ ]] || { echo "invalid jail" >&2; exit 2; }
if [[ $ip =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then :
elif [[ $ip =~ ^[0-9a-fA-F:]{2,39}$ && $ip == *:* ]]; then :
else echo "invalid ip" >&2; exit 2; fi

fail2ban-client status "$jail" >/dev/null 2>&1 || { echo "unknown jail" >&2; exit 4; }
exec fail2ban-client set "$jail" unbanip "$ip"
