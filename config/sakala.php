<?php

declare(strict_types=1);

return [
    'console_url' => env('SAKALA_CONSOLE_URL', 'http://localhost:5173'),

    'rate_limits' => [
        'api' => (int) env('SAKALA_API_RATE_LIMIT', 60),
        'login' => (int) env('SAKALA_LOGIN_RATE_LIMIT', 5),
        'register' => (int) env('SAKALA_REGISTER_RATE_LIMIT', 5),
        'oauth' => (int) env('SAKALA_OAUTH_RATE_LIMIT', 10),
        'email_verification' => (int) env('SAKALA_EMAIL_VERIFICATION_RATE_LIMIT', 5),
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
        // Protocol revisions this control plane can schedule workload for.
        // Nodes reporting another revision may heartbeat but receive no commands.
        'supported_protocol_versions' => array_values(array_filter(array_map(
            static fn (string $value): int => (int) trim($value),
            explode(',', (string) env('SAKALA_AGENT_SUPPORTED_PROTOCOL_VERSIONS', '4')),
        ))),
        // A node whose last heartbeat is older than this is not scheduled work.
        'offline_after_seconds' => (int) env('SAKALA_AGENT_OFFLINE_AFTER_SECONDS', 60),
    ],

    'deployments' => [
        // Walk deployments through a fake lifecycle without a runtime node.
        // Only for local development of the console; never enable where a
        // real agent is connected, because both would drive the same record.
        'simulate' => (bool) env('SAKALA_SIMULATE_DEPLOYMENTS', false),
    ],

    'usage_signals' => [
        'retention_days' => (int) env('SAKALA_USAGE_SIGNALS_RETENTION_DAYS', 30),
        'repeat_failure_threshold' => (int) env('SAKALA_USAGE_SIGNALS_REPEAT_FAILURE_THRESHOLD', 3),
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
        'log_retention_days' => (int) env('SAKALA_LOG_RETENTION_DAYS', 7),
        'log_bounds' => [
            'max_line_length' => (int) env('SAKALA_LOG_MAX_LINE_LENGTH', 4096),
            'max_batch_lines' => (int) env('SAKALA_LOG_MAX_BATCH_LINES', 500),
            'max_total_bytes' => (int) env('SAKALA_LOG_MAX_TOTAL_BYTES', 10 * 1024 * 1024),
            'max_request_bytes' => (int) env('SAKALA_LOG_MAX_REQUEST_BYTES', 1024 * 1024),
        ],
    ],
];
