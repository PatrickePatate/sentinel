<?php

return [
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

    'actions' => [
        'restartable_services' => array_filter(explode(',', (string) env('SENTINEL_RESTARTABLE_SERVICES', ''))),
    ],
];
