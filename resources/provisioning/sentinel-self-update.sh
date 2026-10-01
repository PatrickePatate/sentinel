#!/usr/bin/env bash
# Managed by Sentinel. Installs a client bundle (wrappers + sudoers policy) read from stdin, after validating it.
# It is part of the base install and is never replaced by a remote update: changing it means re-running the
# provisioning one-liner as root. It refuses anything that would widen what the Sentinel user may do beyond the
# fixed list of command shapes below.
#
#   sentinel-self-update            install the bundle read on stdin
#   sentinel-self-update --validate check the bundle read on stdin, install nothing
set -euo pipefail
export LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin

SBIN=/usr/local/sbin
SHARE=/usr/local/share/sentinel
SUDOERS=/etc/sudoers.d/sentinel
UPDATER_VERSION='__UPDATER_VERSION__'

die() { echo "sentinel-self-update: $*" >&2; exit 1; }

mode=install
[[ ${1:-} == --validate ]] && mode=validate
[[ $mode == validate || $EUID -eq 0 ]] || die "must run as root"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir "$work/files"

# --- parse ---------------------------------------------------------------------------------------------------
user=; version=; sudoers_b64=; current=; current_name=; files=()
IFS= read -r header || die "empty bundle"
[[ $header == 'SENTINEL-BUNDLE 1' ]] || die "unsupported bundle format"

while IFS= read -r line; do
    case "$line" in
        'USER '*)    user=${line#USER } ;;
        'VERSION '*) version=${line#VERSION } ;;
        'FILE '*)    current_name=${line#FILE }; current=file ;;
        SUDOERS)     current=sudoers ;;
        .)           current= ;;
        END)         break ;;
        *)
            [[ -n $current ]] || die "unexpected line in bundle"
            [[ $line =~ ^[A-Za-z0-9+/=]*$ ]] || die "invalid payload line"
            if [[ $current == file ]]; then
                [[ $current_name =~ ^sentinel-[a-z0-9-]+$ ]] || die "invalid wrapper name: $current_name"
                [[ $current_name != sentinel-self-update ]] || die "the updater cannot replace itself"
                printf '%s\n' "$line" >> "$work/files/$current_name.b64"
                [[ " ${files[*]:-} " == *" $current_name "* ]] || files+=("$current_name")
            else
                printf '%s\n' "$line" >> "$work/sudoers.b64"
            fi
            ;;
    esac
done

[[ $user =~ ^[a-z_][a-z0-9_-]{0,31}$ && $user != root ]] || die "invalid user"
[[ $version =~ ^[0-9a-f]{12}$ ]] || die "invalid version"
(( ${#files[@]} > 0 && ${#files[@]} <= 32 )) || die "unexpected number of wrappers"
[[ -s $work/sudoers.b64 ]] || die "bundle has no sudoers policy"
[[ $mode == validate ]] || id -u "$user" >/dev/null 2>&1 || die "user $user does not exist"

# The bundle may only manage the user this machine was provisioned for.
if [[ -f $SUDOERS ]] && ! grep -qE "^${user} ALL=\(root\) NOPASSWD: SENTINEL_CMDS$" "$SUDOERS"; then
    die "the installed policy belongs to another user"
fi

for name in "${files[@]}"; do
    base64 -d "$work/files/$name.b64" > "$work/files/$name" 2>/dev/null || die "wrapper $name is not valid base64"
    [[ $(wc -c < "$work/files/$name") -le 65536 ]] || die "wrapper $name is too large"
    [[ $(head -n1 "$work/files/$name") == '#!/usr/bin/env bash' ]] || die "wrapper $name must be a bash script"
done
base64 -d "$work/sudoers.b64" > "$work/sudoers" 2>/dev/null || die "sudoers is not valid base64"

# --- validate the sudoers policy: only command shapes Sentinel may ever ask for -------------------------------
allowed_exact=(
    '/usr/bin/apt-get clean' '/usr/bin/apt-get update' '/usr/bin/certbot renew'
    '/usr/bin/journalctl --vacuum-time\=14d' '/usr/bin/ss -tulpn' '/usr/sbin/nft list ruleset'
    '/usr/sbin/sshd -T' '/usr/sbin/ufw status verbose' "$SBIN/sentinel-self-update"
)
in_aliases=0; saw_alias=0; saw_grant=0
while IFS= read -r line; do
    [[ -z ${line//[[:space:]]/} || $line == \#* ]] && continue

    if [[ $line == "Defaults:${user} env_reset" || $line == "Defaults:${user} !requiretty" || $line == "Defaults:${user} logfile=\"/var/log/sentinel-sudo.log\"" ]]; then
        continue
    elif [[ $line == 'Cmnd_Alias SENTINEL_CMDS = \' ]]; then
        in_aliases=1; saw_alias=1
    elif (( in_aliases )) && [[ $line == "    "* ]]; then
        cmd=${line#    }; cmd=${cmd%, \\}
        ok=0
        for exact in "${allowed_exact[@]}"; do [[ $cmd == "$exact" ]] && ok=1; done
        [[ $cmd =~ ^/usr/local/sbin/sentinel-[a-z0-9-]+\ \*$ ]] && ok=1
        [[ $cmd =~ ^/usr/bin/systemctl\ (restart|reload|reset-failed)\ --\ [A-Za-z0-9@._-]+$ ]] && ok=1
        (( ok )) || die "command not allowed in the policy: $cmd"
        [[ $line == *'\' ]] || in_aliases=0
    elif [[ $line == "${user} ALL=(root) NOPASSWD: SENTINEL_CMDS" ]]; then
        saw_grant=1
    else
        die "line not allowed in the policy: $line"
    fi
done < "$work/sudoers"
(( saw_alias && saw_grant )) || die "incomplete sudoers policy"

if [[ $mode == validate ]]; then echo "bundle $version valid"; exit 0; fi

# --- install ---------------------------------------------------------------------------------------------------
visudo -cf "$work/sudoers" >/dev/null || die "the policy was rejected by visudo, nothing installed"

for name in "${files[@]}"; do
    install -m 0755 -o root -g root "$work/files/$name" "$SBIN/.$name.new"
    mv -f "$SBIN/.$name.new" "$SBIN/$name"
done

# Wrappers that are no longer part of the client are removed (never the updater itself).
for existing in "$SBIN"/sentinel-*; do
    name=${existing##*/}
    [[ $name == sentinel-self-update ]] && continue
    [[ " ${files[*]} " == *" $name "* ]] || rm -f "$existing"
done

install -m 0440 -o root -g root "$work/sudoers" "$SUDOERS.new"
mv -f "$SUDOERS.new" "$SUDOERS"

install -d -m 0755 "$SHARE"
printf 'bundle=%s updater=%s\n' "$version" "$UPDATER_VERSION" > "$SHARE/version.new"
chmod 0644 "$SHARE/version.new"
mv -f "$SHARE/version.new" "$SHARE/version"

echo "installed bundle $version (${#files[@]} wrappers)"
