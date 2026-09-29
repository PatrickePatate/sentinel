#!/usr/bin/env bash
# Managed by Sentinel. Upgrades ONE installed apt package; validates its own input.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 1 ]] || { echo "usage: $0 <package>" >&2; exit 2; }
pkg=$1
[[ $pkg =~ ^[a-z0-9][a-z0-9+.-]{0,99}$ ]] || { echo "invalid package name" >&2; exit 2; }

deny=(__DENYLIST__)
for pattern in "${deny[@]}"; do
    # shellcheck disable=SC2053
    if [[ $pkg == $pattern ]]; then echo "package $pkg is protected" >&2; exit 3; fi
done

dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q '^install ok installed' \
    || { echo "package $pkg is not installed" >&2; exit 4; }

export DEBIAN_FRONTEND=noninteractive
exec apt-get install --only-upgrade --no-remove -y -o Dpkg::Options::=--force-confold -- "$pkg"
