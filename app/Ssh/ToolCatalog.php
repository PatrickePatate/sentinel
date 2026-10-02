<?php

namespace App\Ssh;

use App\Ssh\Tools\Fail2banTestRegexTool;
use App\Ssh\Tools\FixedCommandTool;
use App\Ssh\Tools\HttpProbeTool;
use App\Ssh\Tools\ServiceLogsTool;
use App\Ssh\Tools\ServiceStatusTool;
use App\Ssh\Tools\Tool;
use App\Ssh\Tools\WebConfigTestTool;
use App\Ssh\Tools\WebErrorLogsTool;
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
            new FixedCommandTool('pending_updates', 'Upgradable packages (apt, no refresh).', 'apt list --upgradable 2>/dev/null | tail -n +2 | grep . || echo "no upgradable packages in the local lists (they may be stale: refresh_package_lists updates them)"'),
            new FixedCommandTool('security_updates', 'Pending security updates (apt simulation, no changes).', 'apt-get -s upgrade 2>/dev/null | grep -i security || echo "no pending security updates in the local package lists"'),
            new FixedCommandTool('reboot_required', 'Whether a reboot is pending.', 'test -f /var/run/reboot-required && cat /var/run/reboot-required || echo "no reboot required"'),
            new FixedCommandTool('ssh_config', 'Effective sshd hardening settings.', '{sudo}sshd -T 2>/dev/null | grep -Ei "^(permitrootlogin|passwordauthentication|pubkeyauthentication|port|x11forwarding|maxauthtries) "', ['/usr/sbin/sshd -T']),
            new FixedCommandTool('recent_auth_failures', 'Last 50 failed SSH logins.', 'journalctl -u ssh -u sshd --no-pager -n 500 2>/dev/null | grep -i "failed" | tail -n 50 | grep . || echo "no failed SSH logins in the journal (journald may not keep sshd messages on this machine)"'),
            new FixedCommandTool('logged_in_users', 'Current sessions and last logins.', 'who; last -n 10'),
            new FixedCommandTool('firewall_rules', 'Firewall state (ufw or nftables).', '{sudo}ufw status verbose 2>/dev/null || {sudo}nft list ruleset 2>/dev/null | head -n 100', ['/usr/sbin/ufw status verbose', '/usr/sbin/nft list ruleset']),
            new FixedCommandTool('top_disk_usage', 'Biggest directories under /var, /home, /opt and /srv (find what fills a disk).', 'du -xh --max-depth=2 /var /home /opt /srv 2>/dev/null | sort -rh | head -n 25'),
            new FixedCommandTool('systemd_timers', 'Scheduled systemd timers (what runs periodically, and when it last did).', 'systemctl list-timers --all --no-pager'),
            new FixedCommandTool('kernel_warnings', 'Recent kernel warnings and errors (OOM kills, disk errors, network drops).', 'journalctl -k -p warning --no-pager -n 50 2>&1'),
            new FixedCommandTool('webstack_overview', 'State of the typical web stack units that exist on this machine: web servers, php-fpm, databases, caches, queues, supervisor (running, failed or stopped).', 'systemctl list-units --type=service --all --no-pager --plain --no-legend \'nginx*\' \'apache2*\' \'httpd*\' \'php*-fpm*\' \'mysql*\' \'mariadb*\' \'postgresql*\' \'redis*\' \'valkey*\' \'memcached*\' \'supervisor*\' \'rabbitmq*\' \'elasticsearch*\' \'opensearch*\' \'meilisearch*\' 2>&1 | grep -v " not-found " | grep . || echo "no web stack units found on this machine"'),
            new FixedCommandTool('database_health', 'Whether the local database and cache servers answer (mysqladmin ping, pg_isready, redis-cli ping). An access-denied answer still proves the server is up.', 'for c in "mysqladmin --connect-timeout=3 ping" "pg_isready -t 3" "redis-cli ping"; do bin=${c%% *}; if command -v $bin >/dev/null 2>&1; then echo "$ $c"; $c 2>&1 | head -n 3; fi; done | grep . || echo "no mysql, postgresql or redis client found on this machine"'),
            new FixedCommandTool('web_sites', 'Enabled virtual host files of nginx and apache2 (listing only, not their content).', 'for d in /etc/nginx/sites-enabled /etc/nginx/conf.d /etc/apache2/sites-enabled; do [ -d $d ] && { echo "== $d"; ls -l $d 2>&1; }; done | grep . || echo "no nginx or apache2 site configuration found"'),
            new FixedCommandTool('account_audit', 'Who can log in: accounts with a login shell, uid 0 accounts, sudo group members, sudoers drop-ins, and the fingerprint of every authorized SSH key (never the keys). Read-only.', '{sudo}/usr/local/sbin/sentinel-account-audit 2>&1', ['/usr/local/sbin/sentinel-account-audit *']),
            new FixedCommandTool('failed_jobs', 'Scheduled jobs that failed: timers whose service did not end in success, and cron errors from the last 24 hours.', 'for t in $(systemctl list-timers --all --no-pager --no-legend --plain 2>/dev/null | grep -o "[^ ]*\\.timer"); do s=$(systemctl show -p Unit --value "$t" 2>/dev/null); r=$(systemctl show -p Result --value "$s" 2>/dev/null); [ -n "$r" ] && [ "$r" != success ] && echo "$t -> $s: $r (last run $(systemctl show -p ExecMainExitTimestamp --value "$s" 2>/dev/null))"; done; echo "== cron errors (24h)"; journalctl -u cron -u crond --since -24h -p err --no-pager -n 30 2>/dev/null | grep -v "^-- " | grep . || echo "none"'),
            new FixedCommandTool('backup_status', 'Find out whether backups run: newest files in the usual backup directories, backup-like systemd timers and cron entries.', 'for d in /var/backups /backup /backups /srv/backups /var/lib/automysqlbackup; do [ -d "$d" ] && { echo "== newest files in $d"; find "$d" -maxdepth 2 -type f -printf "%TY-%Tm-%Td %TH:%TM  %s bytes  %p\n" 2>/dev/null | sort -r | head -n 5; }; done; echo "== backup timers"; systemctl list-timers --all --no-pager 2>/dev/null | grep -Ei "backup|borg|restic|dump" || echo "none"; echo "== cron entries"; grep -rEi "backup|borg|restic|mysqldump|pg_dump" /etc/cron.d /etc/cron.daily /etc/crontab 2>/dev/null | head -n 10 || true'),
            new WebConfigTestTool,
            new WebErrorLogsTool,
            new HttpProbeTool,
            new ServiceStatusTool,
            new ServiceLogsTool,
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
