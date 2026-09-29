<?php

return [
    // 'ssh' talks to real machines; 'fake' simulates them (local development).
    'transport' => env('SENTINEL_TRANSPORT', 'ssh'),

    'fake' => [
        'latency_ms' => (int) env('SENTINEL_FAKE_LATENCY_MS', 400),
    ],

    'agent' => [
        'provider' => env('SENTINEL_AI_PROVIDER', 'anthropic'),
        'model' => env('SENTINEL_AI_MODEL'),
    ],

    'gate' => [
        'provider' => env('SENTINEL_GATE_PROVIDER', 'openrouter'),
        'model' => env('SENTINEL_GATE_MODEL'),
        'min_execute_confidence' => 0.95,
        'max_destructive' => 0.05,
        'min_reversible' => 0.8,
        'min_matches_objective' => 0.8,
        'max_autonomous_actions_per_run' => 3,
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

        // Packages the agent may never upgrade, even with human approval (fnmatch patterns).
        'package_denylist' => [
            'openssh-*', 'libc6*', 'systemd*', 'dbus*', 'linux-*', 'grub*', 'docker-*', 'containerd*',
            'mysql-*', 'mariadb-*', 'postgresql*', 'sudo', 'apt', 'dpkg',
        ],

        'reloadable_services' => array_filter(explode(',', (string) env('SENTINEL_RELOADABLE_SERVICES', ''))),
        'restartable_services' => array_filter(explode(',', (string) env('SENTINEL_RESTARTABLE_SERVICES', ''))),
    ],
];
