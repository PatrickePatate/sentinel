#!/usr/bin/env bash
# Managed by Sentinel. Installs ONE package from a fixed list of security tools; validates its own input.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 1 ]] || { echo "usage: $0 <package>" >&2; exit 2; }
pkg=$1
allowed=(__INSTALLABLE__)

found=0
for candidate in "${allowed[@]}"; do [[ $pkg == "$candidate" ]] && found=1; done
(( found )) || { echo "package $pkg is not installable by Sentinel" >&2; exit 3; }

if dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q '^install ok installed'; then
    echo "$pkg is already installed"; exit 0
fi

# Simulate first: refuse if apt would remove anything or pull in too much.
sim=$(apt-get -s install --no-remove --no-install-recommends -- "$pkg" 2>/dev/null) || { echo "apt simulation failed (try refresh_package_lists)" >&2; exit 5; }
grep -q '^Remv ' <<<"$sim" && { echo "install would remove packages, refusing" >&2; exit 6; }
(( $(grep -c '^Inst ' <<<"$sim") <= 25 )) || { echo "install would touch too many packages, refusing" >&2; exit 6; }

export DEBIAN_FRONTEND=noninteractive
exec apt-get install --no-remove --no-install-recommends -y -o Dpkg::Options::=--force-confold -- "$pkg"
