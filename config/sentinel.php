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
    ],

    'agent' => [
        'provider' => env('SENTINEL_AI_PROVIDER', 'anthropic'),
        'model' => env('SENTINEL_AI_MODEL') ?: null,

        // Autonomous (scheduled) scans can use a cheaper model. Each falls back to the default above when unset.
        'scheduled_provider' => env('SENTINEL_SCHEDULED_PROVIDER') ?: null,
        'scheduled_model' => env('SENTINEL_SCHEDULED_MODEL') ?: null,
    ],

    'gate' => [
        'provider' => env('SENTINEL_GATE_PROVIDER', 'openrouter'),
        'model' => env('SENTINEL_GATE_MODEL') ?: null,
        'min_execute_confidence' => 0.95,
        'max_destructive' => 0.05,
        'min_reversible' => 0.8,
        'min_matches_objective' => 0.8,
        'max_autonomous_actions_per_run' => 3,
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
        'use_sudo' => env('SENTINEL_USE_SUDO', true),

        // Packages the agent may upgrade (fnmatch patterns). Empty = none: the action fails closed.
        'package_allowlist' => array_filter(explode(',', (string) env('SENTINEL_UPGRADABLE_PACKAGES', ''))),

        // Packages the agent may never upgrade, nor touch as a dependency, even with human approval (fnmatch patterns).
        'package_denylist' => [
            'openssh-*', 'libc6*', 'systemd*', 'dbus*', 'linux-*', 'grub*', 'docker-*', 'containerd*',
            'mysql-*', 'mariadb-*', 'postgresql*', 'sudo', 'apt', 'apt-*', 'dpkg', 'openssl', 'libssl*', 'ca-certificates',
            'libpam*', 'fail2ban', 'ufw', 'iptables', 'nftables', 'certbot*', 'cron', 'unattended-upgrades',
        ],

        'reloadable_services' => array_filter(explode(',', (string) env('SENTINEL_RELOADABLE_SERVICES', ''))),
        'restartable_services' => array_filter(explode(',', (string) env('SENTINEL_RESTARTABLE_SERVICES', ''))),
    ],
];
