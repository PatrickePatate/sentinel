<?php

return [
    // 'ssh' talks to real machines; 'fake' simulates them (local development).
    'transport' => env('SENTINEL_TRANSPORT', 'ssh'),

    'fake' => [
        'latency_ms' => (int) env('SENTINEL_FAKE_LATENCY_MS', 400),
    ],

    'limits' => [
        // Cost and prompt-injection persistence: bound what is replayed to the model and how often it is called.
        'chat_history_messages' => 20,
        'chat_messages_per_minute' => 8,
        'scans_per_hour_per_user' => 6,
    ],

    'provisioning' => [
        // Unprivileged account Sentinel creates on each machine (and connects as).
        'user' => env('SENTINEL_AGENT_USER', 'sentinel'),

        // Public address(es) Sentinel connects from, IPv4 and/or IPv6 (comma separated, CIDR allowed). The deployed key only
        // works from there, and fail2ban never bans them. Empty = the key is accepted from anywhere.
        'source_ips' => array_filter(array_map('trim', explode(',', (string) env('SENTINEL_SOURCE_IPS', '')))),
    ],

    'scheduling' => [
        // Choices offered per machine for autonomous scans (minutes => label). Kept coarse: every scan costs LLM calls.
        'frequencies' => [
            0 => 'Manual only',
            60 => 'Every hour',
            180 => 'Every 3 hours',
            360 => 'Every 6 hours',
            720 => 'Every 12 hours',
            1440 => 'Every day',
            10080 => 'Every week',
        ],
        'objective' => 'Run a security and health audit',

        // Scheduled web server checks first look at the stack with plain commands and only call the model when something is wrong
        // (and anyway every few hours, for what a plain check cannot see).
        'precheck' => [
            'enabled' => (bool) env('SENTINEL_PRECHECK', true),
            'full_check_hours' => 6,
            // Choices offered per machine for how often the AI looks too (hours => label). 0: only when the plain check finds a problem.
            'full_check_choices' => [0 => 'Only when the quick check finds a problem', 1 => 'Every hour', 3 => 'Every 3 hours', 6 => 'Every 6 hours', 12 => 'Every 12 hours', 24 => 'Every day'],
        ],

        // Kinds of scan. Each has its own objective, extra instructions for the agent and its own schedule (two columns of the machine).
        'profiles' => [
            'audit' => [
                'label' => 'Security & health audit',
                'objective' => 'Run a security and health audit',
                'instructions' => '',
                'interval_column' => 'scan_interval_minutes',
                'last_column' => 'last_scan_at',
                'frequencies' => null, // the list above
            ],
            'webserver' => [
                'label' => 'Web server check',
                'objective' => 'Check that every component of the web stack (web server, php-fpm, database, cache, queue) runs correctly. Start what crashed and repair a broken web server configuration.',
                'instructions' => <<<'TXT'
This is a WEB SERVER HEALTH CHECK and you are explicitly asked to repair what you find: call the corrective action tools themselves (start_crashed_service, rollback_web_config, reload_web_config), NOT their propose_* variants. The risk gate still decides for each call whether it runs, waits for a human or is refused; only if it holds or refuses a call is a propose_* filing useful, and never to retry the same call. Work in this order:
1. webstack_overview lists the web stack units on the machine. Compare them with the administrator notes (what is expected to run): an expected component that is missing or down is a finding, and so is an unexpected one. Without notes, judge from what is installed.
2. Test each component you found: web_config_test for nginx / apache2 / php-fpm, database_health for mysql / postgresql / redis, web_sites then http_probe for the sites (and the virtual hosts named in the notes).
3. For everything failed, stopped or erroring, find out WHY before acting: service_logs, web_error_logs, kernel_warnings (OOM kills), disk_usage (a full disk kills databases).
4. Repair with the smallest step that works, one at a time, and recheck after each:
   - configuration fails its test -> rollback_web_config (restores the last configuration that passed; the broken one is kept aside), then start_crashed_service if the unit is still down;
   - configuration valid but the unit is down -> start_crashed_service;
   - a valid configuration that is on disk but not applied -> reload_web_config.
   Never restart a service that is running. If a cause is outside these actions (disk full, out of memory, a bug in the application, a certificate), do not loop: report it with the evidence and the fix a human should apply.
5. Call machine_history: a repair that already ran 3 or more times this week means the service keeps failing, so report the root cause instead of repeating it. If the notes mention backups, check backup_status. If you learned a durable fact the notes lack (an extra component, where the sites live), file it once with suggest_memory_note.
6. Finish with the state of every component (running / repaired / still broken), what you changed (only what the action tools reported as executed), and what is waiting for approval or was refused.
Severity: none if everything was healthy; low if you repaired something and it now works; medium if something is degraded but serving; high if a component of the site is still down; critical if the site is down for visitors.
TXT,
                // Opt-in per machine: nothing of this runs (scheduled, manual or triggered by a down site) until an admin enables it.
                'enabled_column' => 'webserver_enabled',
                'interval_column' => 'webserver_interval_minutes',
                'last_column' => 'last_webserver_scan_at',
                'frequencies' => [
                    0 => 'Manual only',
                    5 => 'Every 5 minutes',
                    15 => 'Every 15 minutes',
                    30 => 'Every 30 minutes',
                    60 => 'Every hour',
                    180 => 'Every 3 hours',
                    720 => 'Every 12 hours',
                    1440 => 'Every day',
                ],
            ],
        ],
    ],

    // Push updates to the dashboard over WebSockets (Laravel Reverb: php artisan reverb:start) instead of polling every few seconds.
    'realtime' => ['enabled' => (bool) env('SENTINEL_REALTIME', false)],

    'sites' => [
        // Public sites listed on a machine are checked from Sentinel every five minutes.
        'failures_before_alert' => 2,
        'cert_warning_days' => 14,
        // A site that stays down starts a web server check on its machine (which repairs what it can through the risk gate).
        'scan_on_down' => (bool) env('SENTINEL_SCAN_ON_SITE_DOWN', true),
    ],

    'agent' => [
        'provider' => env('SENTINEL_AI_PROVIDER', 'anthropic'),
        'model' => env('SENTINEL_AI_MODEL') ?: null,

        // Autonomous (scheduled) scans can use a cheaper model. Each falls back to the default above when unset.
        'scheduled_provider' => env('SENTINEL_SCHEDULED_PROVIDER') ?: null,
        'scheduled_model' => env('SENTINEL_SCHEDULED_MODEL') ?: null,
    ],

    // USD per million tokens, by model name, used to estimate the cost of a run (providers only report tokens).
    // Rates set here win over the ones fetched by `php artisan sentinel:pricing` (weekly). A model with neither shows its tokens but no cost. 'cache_read' / 'cache_write' are optional
    // and default to the input rate. Example:
    //   'claude-sonnet-5-5' => ['input' => 0.0, 'output' => 0.0, 'cache_read' => 0.0, 'cache_write' => 0.0],
    'pricing' => [],

    'gate' => [
        'provider' => env('SENTINEL_GATE_PROVIDER', 'openrouter'),
        'model' => env('SENTINEL_GATE_MODEL') ?: null,
        'min_execute_confidence' => 0.95,
        'max_destructive' => 0.05,
        'min_reversible' => 0.8,
        'min_matches_objective' => 0.8,
        'max_autonomous_actions_per_run' => 3,
        // The same command running this many times in 24 hours is held for a human instead of run again.
        'flap_threshold' => 3,
        'pending_ttl_hours' => 24,
    ],

    'fail2ban' => [
        // Log files the agent may test regexes against / attach jails to (key => absolute path).
        'logs' => [
            'sshd' => '/var/log/auth.log',
            'syslog' => '/var/log/syslog',
            'nginx-access' => '/var/log/nginx/access.log',
            'nginx-error' => '/var/log/nginx/error.log',
        ],
    ],

    'actions' => [
        // Ed25519 key that signs client bundles; machines only install bundles signed by it. Kept out of the database on
        // purpose: a database dump with the SSH keys must not be enough to push a bundle. Created on first use.
        'bundle_signing_key' => env('SENTINEL_BUNDLE_SIGNING_KEY_PATH', storage_path('app/private/bundle-signing.key')),

        'use_sudo' => env('SENTINEL_USE_SUDO', true),

        // Packages the agent may upgrade (fnmatch patterns). Empty = none: the action fails closed.
        'package_allowlist' => array_filter(explode(',', (string) env('SENTINEL_UPGRADABLE_PACKAGES', ''))),

        // Packages the agent may never upgrade, nor touch as a dependency, even with human approval (fnmatch patterns).
        'package_denylist' => [
            'openssh-*', 'libc6*', 'systemd*', 'dbus*', 'linux-*', 'grub*', 'docker-*', 'containerd*',
            'mysql-*', 'mariadb-*', 'postgresql*', 'sudo', 'apt', 'apt-*', 'dpkg', 'openssl', 'libssl*', 'ca-certificates',
            'libpam*', 'fail2ban', 'ufw', 'iptables', 'nftables', 'certbot*', 'cron', 'unattended-upgrades',
        ],

        // Security tools the agent may install when missing (exact package names, installed by a root-owned wrapper).
        'installable_packages' => ['fail2ban', 'unattended-upgrades'],

        // Units the agent may start when they are down (shell glob patterns, checked again by the root wrapper). Web stack only.
        'recoverable_services' => [
            'nginx', 'apache2', 'httpd', 'php*-fpm', 'mysql', 'mysqld', 'mariadb', 'postgresql', 'postgresql@*', 'redis', 'redis-server',
            'valkey', 'valkey-server', 'memcached', 'supervisor', 'rabbitmq-server', 'elasticsearch', 'opensearch', 'meilisearch',
            ...array_filter(array_map('trim', explode(',', (string) env('SENTINEL_RECOVERABLE_SERVICES', '')))),
        ],

        'reloadable_services' => array_filter(explode(',', (string) env('SENTINEL_RELOADABLE_SERVICES', ''))),
        'restartable_services' => array_filter(explode(',', (string) env('SENTINEL_RESTARTABLE_SERVICES', ''))),
    ],
];
