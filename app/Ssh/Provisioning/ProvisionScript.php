<?php

namespace App\Ssh\Provisioning;

use App\Models\Machine;
use InvalidArgumentException;

/**
 * Renders the bash script an administrator runs ONCE, as root, on a machine to let
 * Sentinel in: locked unprivileged user, restricted SSH key, root-owned validating
 * wrappers, and a sudoers policy generated from the catalogs and checked by visudo.
 */
class ProvisionScript
{
    private const WRAPPERS = [
        'sentinel-upgrade-package',
        'sentinel-fail2ban-unban',
        'sentinel-fail2ban-filter',
        'sentinel-fail2ban-jail',
        'sentinel-fail2ban-remove',
    ];

    public function __construct(private SudoersBuilder $sudoers) {}

    public function render(Machine $machine, ?string $fromIp = null): string
    {
        $user = $machine->username;
        SudoersBuilder::assertValidUser($user);

        if ($fromIp !== null && ! filter_var($fromIp, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('--from must be a single IP address.');
        }

        $publicKey = $machine->publicKey();
        $authorizedOptions = 'restrict'.($fromIp ? ',from="'.$fromIp.'"' : '');

        $wrappers = '';
        foreach (self::WRAPPERS as $wrapper) {
            $wrappers .= $this->installFile('/usr/local/sbin/'.$wrapper, $this->wrapperBody($wrapper, $fromIp), '0755')."\n";
        }

        $sudoers = $this->sudoers->render($user);

        return <<<BASH
#!/usr/bin/env bash
# Sentinel provisioning for "{$machine->name}" - generated, review before running as root.
# Creates user "{$user}" (no password, SSH key only, restricted) and grants it sudo for a fixed list of
# commands. Safe to re-run. To revoke: php artisan sentinel:revoke <machine>
set -euo pipefail

[[ \$EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
[[ -f /etc/debian_version ]] || { echo "Debian/Ubuntu only." >&2; exit 1; }
command -v visudo >/dev/null || { echo "visudo not found (install sudo)." >&2; exit 1; }

# 1. Unprivileged user, no usable password ("*": password login impossible, key login still allowed even with UsePAM off), can read logs but not become root freely
if ! id -u {$user} >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash --comment "Sentinel agent" {$user}
fi
usermod -p '*' {$user}
for group in systemd-journal adm; do
    getent group "\$group" >/dev/null && usermod -aG "\$group" {$user}
done

# 2. SSH key, restricted (no forwarding, no pty, no agent)
home=\$(getent passwd {$user} | cut -d: -f6)
install -d -m 700 -o {$user} -g {$user} "\$home/.ssh"
printf '%s\\n' '{$authorizedOptions} {$publicKey}' > "\$home/.ssh/authorized_keys"
chown {$user}:{$user} "\$home/.ssh/authorized_keys"
chmod 600 "\$home/.ssh/authorized_keys"

# 3. Root-owned wrappers: they re-validate their arguments, whatever the caller sends
{$wrappers}
# 4. sudoers: written to a temp file, checked with visudo, then installed atomically
tmp=\$(mktemp /etc/sudoers.d/.sentinel-XXXXXX)
trap 'rm -f "\$tmp"' EXIT
cat > "\$tmp" <<'SENTINEL_SUDOERS'
{$sudoers}SENTINEL_SUDOERS
chmod 0440 "\$tmp"
visudo -cf "\$tmp" >/dev/null || { echo "Generated sudoers is invalid, nothing installed." >&2; exit 1; }
mv -f "\$tmp" /etc/sudoers.d/sentinel
trap - EXIT

# 5. Sanity checks (informational, never fatal)
if command -v sshd >/dev/null && sshd -T 2>/dev/null | grep -qE '^allow(users|groups) '; then
    sshd -T 2>/dev/null | grep -qE "^allowusers {$user}$" \
        || echo "WARNING: sshd restricts logins (AllowUsers/AllowGroups) and does not list {$user}: add it, then reload sshd." >&2
fi
for tool in fail2ban-client ufw certbot; do
    command -v "\$tool" >/dev/null || echo "Note: \$tool is not installed here: the matching Sentinel tools will report it as unavailable."
done

echo "Done. Effective sudo rights of {$user}:"
sudo -l -U {$user}
echo "Host key fingerprints of this machine (paste the SHA256:... of the one Sentinel shows when pinning):"
for pub in /etc/ssh/ssh_host_*_key.pub; do
    [[ -f "\$pub" ]] && ssh-keygen -lf "\$pub"
done

BASH;
    }

    public function wrapperBody(string $wrapper, ?string $fromIp = null): string
    {
        $body = file_get_contents(resource_path("provisioning/{$wrapper}.sh"));

        $logCases = collect(config('sentinel.fail2ban.logs'))
            ->map(fn (string $path, string $key) => "    {$key}) logpath={$path} ;;")
            ->implode("\n");

        return strtr($body, [
            '__ALLOWLIST__' => collect(config('sentinel.actions.package_allowlist'))->filter()->map(fn ($p) => "'{$p}'")->implode(' '),
            '__DENYLIST__' => collect(config('sentinel.actions.package_denylist'))->map(fn ($p) => "'{$p}'")->implode(' '),
            '__LOG_CASES__' => $logCases,
            '__IGNOREIP__' => $fromIp ?? '',
        ]);
    }

    private function installFile(string $path, string $content, string $mode): string
    {
        $delimiter = 'SENTINEL_FILE_'.strtoupper(substr(md5($path), 0, 8));

        return "cat > {$path} <<'{$delimiter}'\n".rtrim($content)."\n{$delimiter}\nchown root:root {$path}\nchmod {$mode} {$path}";
    }
}
