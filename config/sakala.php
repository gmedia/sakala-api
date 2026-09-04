<?php

declare(strict_types=1);

return [
    'console_url' => env('SAKALA_CONSOLE_URL', 'http://localhost:5173'),

    'rate_limits' => [
        'api' => (int) env('SAKALA_API_RATE_LIMIT', 60),
        'login' => (int) env('SAKALA_LOGIN_RATE_LIMIT', 5),
        'oauth' => (int) env('SAKALA_OAUTH_RATE_LIMIT', 10),
        'feedback' => (int) env('SAKALA_FEEDBACK_RATE_LIMIT', 5),
    ],

    'project' => [
        'default_domain' => env('SAKALA_PROJECT_DEFAULT_DOMAIN', 'run.sakala.dev'),
        'reserved_slugs' => [
            'api', 'app', 'console', 'agent', 'admin', 'www', 'sakala', 'webhook', 'docs',
            'support', 'help', 'status', 'mail', 'test', 'run',
        ],
    ],

    'agent' => [
        'command_batch_size' => (int) env('SAKALA_AGENT_COMMAND_BATCH_SIZE', 10),
    ],

    'pilot_limits' => [
        'max_projects_per_user' => (int) env('SAKALA_MAX_PROJECTS_PER_USER', 3),
        'max_active_deployments_per_user' => (int) env('SAKALA_MAX_ACTIVE_DEPLOYMENTS_PER_USER', 2),
        'max_active_deployments_per_project' => (int) env('SAKALA_MAX_ACTIVE_DEPLOYMENTS_PER_PROJECT', 1),
        'resources' => [
            'default_memory_mb' => (int) env('SAKALA_DEFAULT_CONTAINER_MEMORY_MB', 256),
            'max_memory_mb' => (int) env('SAKALA_MAX_CONTAINER_MEMORY_MB', 512),
            'default_cpu_millis' => (int) env('SAKALA_DEFAULT_CONTAINER_CPU_MILLIS', 500),
            'max_cpu_millis' => (int) env('SAKALA_MAX_CONTAINER_CPU_MILLIS', 1000),
            'default_pids_limit' => (int) env('SAKALA_DEFAULT_CONTAINER_PIDS_LIMIT', 128),
            'max_pids_limit' => (int) env('SAKALA_MAX_CONTAINER_PIDS_LIMIT', 256),
        ],
        'timeouts' => [
            'build_timeout_seconds' => (int) env('SAKALA_BUILD_TIMEOUT_SECONDS', 600),
            'start_timeout_seconds' => (int) env('SAKALA_START_TIMEOUT_SECONDS', 120),
            'command_timeout_seconds' => (int) env('SAKALA_COMMAND_TIMEOUT_SECONDS', 900),
        ],
        'log_bounds' => [
            'max_line_length' => (int) env('SAKALA_LOG_MAX_LINE_LENGTH', 4096),
            'max_batch_lines' => (int) env('SAKALA_LOG_MAX_BATCH_LINES', 500),
            'max_total_bytes' => (int) env('SAKALA_LOG_MAX_TOTAL_BYTES', 10 * 1024 * 1024),
        ],
    ],
];
