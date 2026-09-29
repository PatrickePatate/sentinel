#!/usr/bin/env bash
# Managed by Sentinel. Upgrades ONE installed apt package; validates its own input.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 1 ]] || { echo "usage: $0 <package>" >&2; exit 2; }
pkg=$1
[[ $pkg =~ ^[a-z0-9][a-z0-9+.-]{0,99}$ ]] || { echo "invalid package name" >&2; exit 2; }

allow=(__ALLOWLIST__)
deny=(__DENYLIST__)

matches_any() {
    local name=$1 pattern
    shift
    for pattern in "$@"; do
        # shellcheck disable=SC2053
        [[ $name == $pattern ]] && return 0
    done
    return 1
}

# Allowlist first (fail closed: an empty allowlist upgrades nothing), denylist on top.
matches_any "$pkg" "${allow[@]}" || { echo "package $pkg is not on the allowlist" >&2; exit 3; }
! matches_any "$pkg" "${deny[@]}" || { echo "package $pkg is protected" >&2; exit 3; }

dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q '^install ok installed' \
    || { echo "package $pkg is not installed" >&2; exit 4; }

# Simulate first: refuse if apt would also touch a protected package or remove anything.
sim=$(apt-get -s install --only-upgrade --no-remove -- "$pkg" 2>/dev/null) || { echo "apt simulation failed" >&2; exit 5; }
touched=0
while read -r op name _; do
    case "$op" in
        Remv) echo "upgrade would remove $name, refusing" >&2; exit 6 ;;
        Inst)
            touched=$((touched + 1))
            if matches_any "$name" "${deny[@]}"; then echo "upgrade would also touch protected package $name, refusing" >&2; exit 6; fi
            ;;
    esac
done <<<"$sim"
(( touched <= 15 )) || { echo "upgrade would touch $touched packages, refusing" >&2; exit 6; }

export DEBIAN_FRONTEND=noninteractive
exec apt-get install --only-upgrade --no-remove -y -o Dpkg::Options::=--force-confold -- "$pkg"
