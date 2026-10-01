<?php

namespace App\Ssh\Provisioning;

use App\Models\Machine;

/**
 * Renders the bash script an administrator runs ONCE, as root, on a machine to let Sentinel in: locked
 * unprivileged user, restricted SSH key, and the client bundle (root-owned validating wrappers + a sudoers policy
 * checked by visudo) installed through the same updater later used for remote updates.
 *
 * Meant to be piped: `curl -fsSL <url> | sudo bash`. The whole script lives in a function so bash has read all of it
 * before anything runs, and no command can swallow the rest of the script from stdin.
 */
class ProvisionScript
{
    public function __construct(private ClientBundle $bundle) {}

    /** @param  string|null  $callbackUrl  Where the script reports the host key fingerprints (pins them without any copy-paste). */
    public function render(Machine $machine, ?string $callbackUrl = null): string
    {
        $user = $machine->username;
        SudoersBuilder::assertValidUser($user);
        $name = self::commentSafe($machine->name);

        $publicKey = $machine->publicKey();
        $sourceIps = SourceIps::configured();
        $authorizedOptions = 'restrict'.($sourceIps ? ',from="'.implode(',', $sourceIps).'"' : '');
        $updater = ClientBundle::UPDATER_PATH;
        $version = $this->bundle->version($user);
        $updaterBody = rtrim($this->bundle->updaterBody());
        $bundle = rtrim($this->bundle->render($machine));
        $callback = $callbackUrl ? "'".str_replace("'", "'\\''", $callbackUrl)."'" : '';

        return <<<BASH
#!/usr/bin/env bash
# Sentinel provisioning for "{$name}" - generated, review before running as root.
# Creates user "{$user}" (no password, SSH key only, restricted) and grants it sudo for a fixed list of
# commands. Safe to re-run. To revoke: php artisan sentinel:revoke <machine>
set -euo pipefail

main() {
    [[ \$EUID -eq 0 ]] || { echo "Run as root (curl ... | sudo bash)." >&2; exit 1; }
    [[ -f /etc/debian_version ]] || { echo "Debian/Ubuntu only." >&2; exit 1; }
    command -v visudo >/dev/null || { echo "visudo not found (install sudo)." >&2; exit 1; }
    command -v base64 >/dev/null || { echo "base64 not found." >&2; exit 1; }

    # 1. Unprivileged user, no usable password ("*": password login impossible, key login still allowed even with UsePAM off)
    if ! id -u {$user} >/dev/null 2>&1; then
        useradd --create-home --shell /bin/bash --comment "Sentinel agent" {$user}
    fi
    usermod -p '*' {$user}
    for group in systemd-journal adm; do
        getent group "\$group" >/dev/null && usermod -aG "\$group" {$user}
    done

    # 2. SSH key, restricted (no forwarding, no pty, no agent)
    local home
    home=\$(getent passwd {$user} | cut -d: -f6)
    install -d -m 700 -o {$user} -g {$user} "\$home/.ssh"
    printf '%s\\n' '{$authorizedOptions} {$publicKey}' > "\$home/.ssh/authorized_keys"
    chown {$user}:{$user} "\$home/.ssh/authorized_keys"
    chmod 600 "\$home/.ssh/authorized_keys"

    # 3. The updater (root-owned; it validates every bundle it is given, and cannot be replaced remotely)
    cat > {$updater} <<'SENTINEL_UPDATER'
{$updaterBody}
SENTINEL_UPDATER
    chown root:root {$updater}
    chmod 0755 {$updater}

    # 4. The client bundle: root-owned wrappers that re-validate their arguments + the sudoers policy (checked with visudo)
    {$updater} <<'SENTINEL_BUNDLE'
{$bundle}
SENTINEL_BUNDLE

    # 5. Sanity checks (informational, never fatal)
    if command -v sshd >/dev/null && sshd -T 2>/dev/null | grep -qE '^allow(users|groups) '; then
        sshd -T 2>/dev/null | grep -qE "^allowusers {$user}\$" \\
            || echo "WARNING: sshd restricts logins (AllowUsers/AllowGroups) and does not list {$user}: add it, then reload sshd." >&2
    fi
    for tool in fail2ban-client ufw certbot; do
        command -v "\$tool" >/dev/null || echo "Note: \$tool is not installed here: the matching Sentinel tools will report it as unavailable."
    done

    echo "Done. Effective sudo rights of {$user}:"
    sudo -l -U {$user}

    # 6. Host keys: shown here, and reported to Sentinel over TLS so it can pin the right one without copy-paste
    local fingerprints="" pub
    for pub in /etc/ssh/ssh_host_*_key.pub; do
        [[ -f "\$pub" ]] || continue
        fingerprints+="\$(ssh-keygen -lf "\$pub" | awk '{print \$2}')"\$'\\n'
    done
    echo "Host key fingerprints of this machine:"
    printf '%s' "\$fingerprints" | sed 's/^/  /'

    local callback={$callback}
    if [[ -n "\$callback" ]]; then
        if command -v curl >/dev/null; then
            echo "Reporting to Sentinel..."
            curl -fsS -m 60 --data-urlencode "fingerprints=\$fingerprints" --data-urlencode "version={$version}" "\$callback" </dev/null \\
                || echo "Could not reach Sentinel (\$callback): pin the host key from the dashboard instead." >&2
        else
            echo "curl is not installed: pin the host key from the dashboard using one of the fingerprints above." >&2
        fi
    fi
}

main "\$@"

BASH;
    }

    public function wrapperBody(string $wrapper): string
    {
        return $this->bundle->wrappers()[$wrapper];
    }

    /** The machine name as it may appear in a comment of a root script: on one line, whatever was stored. */
    public static function commentSafe(string $name): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name) ?? '';
    }
}
