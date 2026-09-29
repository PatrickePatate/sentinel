<?php

return [
    'agent' => [
        'provider' => env('SENTINEL_AI_PROVIDER', 'anthropic'),
        'model' => env('SENTINEL_AI_MODEL'),
    ],
];
