<?php

declare(strict_types=1);

return [
    'console_url' => env('SAKALA_CONSOLE_URL', 'http://localhost:5173'),

    'rate_limits' => [
        'api' => (int) env('SAKALA_API_RATE_LIMIT', 60),
    ],

    'project' => [
        'default_domain' => env('SAKALA_PROJECT_NAME', 'run.sakala.dev'),
        'reserved_slug' => [
            'api', 'app', 'console', 'agent', 'admin', 'www', 'sakala', 'webhook', 'docs',
            'support', 'help', 'status', 'mail', 'test', 'run',
        ],
    ],
];
