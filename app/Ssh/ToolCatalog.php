<?php

namespace App\Ssh;

use App\Ssh\Tools\Fail2banTestRegexTool;
use App\Ssh\Tools\FixedCommandTool;
use App\Ssh\Tools\ServiceStatusTool;
use App\Ssh\Tools\Tool;
use InvalidArgumentException;

/**
 * The only commands the agent can ever cause to run. Adding a capability means
 * adding a read-only Tool here, reviewed like any other code.
 */
class ToolCatalog
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param  iterable<Tool>  $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public static function default(): self
    {
        return new self([
            new FixedCommandTool('system_info', 'OS release, kernel, uptime and load average.', 'cat /etc/os-release; uname -a; uptime'),
            new FixedCommandTool('disk_usage', 'Filesystem usage (df).', 'df -hT -x tmpfs -x devtmpfs'),
            new FixedCommandTool('memory_usage', 'Memory and swap usage.', 'free -m'),
            new FixedCommandTool('top_processes', 'Top 15 processes by CPU and memory.', 'ps aux --sort=-%cpu | head -n 16'),
            new FixedCommandTool('failed_services', 'systemd units in failed state.', 'systemctl --failed --no-pager'),
            new FixedCommandTool('listening_ports', 'Listening TCP/UDP sockets with owning process.', '{sudo}ss -tulpn 2>&1', ['/usr/bin/ss -tulpn']),
            new FixedCommandTool('pending_updates', 'Upgradable packages (apt, no refresh).', 'apt list --upgradable 2>/dev/null'),
            new FixedCommandTool('security_updates', 'Pending security updates (apt simulation, no changes).', 'apt-get -s upgrade 2>/dev/null | grep -i security'),
            new FixedCommandTool('reboot_required', 'Whether a reboot is pending.', 'test -f /var/run/reboot-required && cat /var/run/reboot-required || echo "no reboot required"'),
            new FixedCommandTool('ssh_config', 'Effective sshd hardening settings.', '{sudo}sshd -T 2>/dev/null | grep -Ei "^(permitrootlogin|passwordauthentication|pubkeyauthentication|port|x11forwarding|maxauthtries) "', ['/usr/sbin/sshd -T']),
            new FixedCommandTool('recent_auth_failures', 'Last 50 failed SSH logins.', 'journalctl -u ssh -u sshd --no-pager -n 500 2>/dev/null | grep -i "failed" | tail -n 50'),
            new FixedCommandTool('logged_in_users', 'Current sessions and last logins.', 'who; last -n 10'),
            new FixedCommandTool('firewall_rules', 'Firewall state (ufw or nftables).', '{sudo}ufw status verbose 2>/dev/null || {sudo}nft list ruleset 2>/dev/null | head -n 100', ['/usr/sbin/ufw status verbose', '/usr/sbin/nft list ruleset']),
            new ServiceStatusTool,
            new Fail2banTestRegexTool,
        ]);
    }

    public function get(string $name): Tool
    {
        return $this->tools[$name] ?? throw new InvalidArgumentException("Unknown tool: {$name}");
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }
}
