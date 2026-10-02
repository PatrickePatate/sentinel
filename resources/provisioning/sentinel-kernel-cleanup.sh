#!/usr/bin/env bash
# Managed by Sentinel. Purges old kernel packages, always keeping two kernels: the running one and the newest other
# one (a fallback to boot). Refuses if apt would remove anything that is not one of those old kernel packages.
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

[[ $# -eq 0 ]] || { echo "usage: $0" >&2; exit 2; }
command -v apt-get >/dev/null || { echo "not an apt system" >&2; exit 4; }

running=$(uname -r)
versions=$(dpkg-query -W -f='${Package} ${Status}\n' 'linux-image-[0-9]*' 2>/dev/null \
    | awk '/install ok installed/ {sub(/^linux-image-/, "", $1); print $1}' | sort -V)
grep -qxF -- "$running" <<<"$versions" || { echo "the running kernel $running is not an installed package, refusing" >&2; exit 4; }

# The newest kernel that is not the running one: the next boot, or the fallback when the newest is running.
newest=$(grep -vxF -- "$running" <<<"$versions" | tail -n 1 || true)

old=()
for version in $versions; do
    [[ $version == "$running" || $version == "$newest" ]] && continue
    for pkg in linux-image linux-headers linux-modules linux-modules-extra; do
        dpkg-query -W -f='${Status}' "$pkg-$version" 2>/dev/null | grep -q 'install ok installed' && old+=("$pkg-$version")
    done
done

(( ${#old[@]} > 0 )) || { echo "nothing to remove: only $running (running)${newest:+ and $newest} are installed"; exit 0; }

# Simulate first: every removal must be one of the old kernel packages listed above.
sim=$(apt-get -s purge -- "${old[@]}" 2>/dev/null) || { echo "apt simulation failed" >&2; exit 5; }
while read -r _ pkg _; do
    [[ " ${old[*]} " == *" $pkg "* ]] || { echo "apt would also remove $pkg, refusing" >&2; exit 6; }
done < <(grep -E '^(Remv|Purg) ' <<<"$sim")
grep -q '^Inst ' <<<"$sim" && { echo "apt would install packages, refusing" >&2; exit 6; }

echo "keeping $running (running) and $newest; removing ${old[*]}"
export DEBIAN_FRONTEND=noninteractive
apt-get purge -y -- "${old[@]}"
df -h /boot 2>/dev/null | tail -n 1
