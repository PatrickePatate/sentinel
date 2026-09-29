<?php

return [
    'llm' => [
        'default' => env('SENTINEL_LLM', 'anthropic'),
        'providers' => [
            'anthropic' => [
                'driver' => 'anthropic',
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            ],
            'openai' => [
                'driver' => 'openai',
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-4o'),
            ],
        ],
    ],

    'agent' => [
        'max_steps' => 15,
    ],
];
