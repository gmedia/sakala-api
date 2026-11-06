<?php

declare(strict_types=1);

$emailDeliveryOverride = env('SAKALA_EMAIL_DELIVERY_ENABLED');

return [
    'console_url' => env('SAKALA_CONSOLE_URL', 'http://localhost:5173'),

    // Whether email can really reach people. When the env is unset or empty it
    // follows mail.default (same env and default as config/mail.php): the log
    // and array mailers deliver nothing. Local development sets it to true on
    // purpose, because it reads the links from the log.
    'email_delivery_enabled' => $emailDeliveryOverride === null || $emailDeliveryOverride === ''
        ? ! in_array(env('MAIL_MAILER', 'log'), ['log', 'array'], true)
        : filter_var($emailDeliveryOverride, FILTER_VALIDATE_BOOLEAN),

    'rate_limits' => [
        'api' => (int) env('SAKALA_API_RATE_LIMIT', 240),
        'api_guest' => (int) env('SAKALA_API_GUEST_RATE_LIMIT', 60),
        'login' => (int) env('SAKALA_LOGIN_RATE_LIMIT', 5),
        'register' => (int) env('SAKALA_REGISTER_RATE_LIMIT', 5),
        'oauth' => (int) env('SAKALA_OAUTH_RATE_LIMIT', 10),
        'email_verification' => (int) env('SAKALA_EMAIL_VERIFICATION_RATE_LIMIT', 5),
        'feedback' => (int) env('SAKALA_FEEDBACK_RATE_LIMIT', 5),
        'password_reset' => (int) env('SAKALA_PASSWORD_RESET_RATE_LIMIT', 5),
        'account_security' => (int) env('SAKALA_ACCOUNT_SECURITY_RATE_LIMIT', 5),
        'inspection' => (int) env('SAKALA_INSPECTION_RATE_LIMIT', 5),
        // Per hour, unlike the others: an export is the heaviest read a user can ask for.
        'account_export' => (int) env('SAKALA_ACCOUNT_EXPORT_RATE_LIMIT', 3),
    ],

    // Current versions of the published documents, stored per user on acceptance.
    // A new terms or acceptable use version asks every user to accept again; a new
    // privacy notice does not, because it is a notice and not what processing rests on.
    'legal' => [
        'versions' => [
            'terms' => env('SAKALA_LEGAL_TERMS_VERSION', 'syarat-v1'),
            'acceptable_use' => env('SAKALA_LEGAL_ACCEPTABLE_USE_VERSION', 'penggunaan-wajar-v1'),
            'privacy_notice' => env('SAKALA_LEGAL_PRIVACY_NOTICE_VERSION', 'privasi-v1'),
        ],
    ],

    'contact' => [
        'abuse_email' => env('SAKALA_ABUSE_EMAIL', 'abuse@sakala.dev'),
        'privacy_email' => env('SAKALA_PRIVACY_EMAIL', 'privacy@sakala.dev'),
    ],

    'account' => [
        // Most items per collection in one data export.
        'export_max_items' => (int) env('SAKALA_ACCOUNT_EXPORT_MAX_ITEMS', 1000),
        // How long after a provider login an account without a password may do
        // something that asks for re-authentication.
        'oauth_reauthentication_minutes' => (int) env('SAKALA_OAUTH_REAUTHENTICATION_MINUTES', 15),
    ],

    'notifications' => [
        'retention_days' => (int) env('SAKALA_NOTIFICATION_RETENTION_DAYS', 90),
    ],

    'project' => [
        'default_domain' => env('SAKALA_PROJECT_DEFAULT_DOMAIN', 'run.sakala.dev'),
        'url_scheme' => env('SAKALA_PROJECT_URL_SCHEME', 'https'),
        'url_port' => env('SAKALA_PROJECT_URL_PORT'),
        // A deleted project is kept this long, then projects:purge-deleted
        // removes it for good. GET app/pilot-limits gives the console this number.
        'deleted_retention_days' => (int) env('SAKALA_DELETED_PROJECT_RETENTION_DAYS', 30),
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
        // Added to a command's execution timeout to form its lease after
        // claim; covers the agent's post-commit finalization grace and HTTP
        // round trips before the control plane treats the command as abandoned.
        'lease_grace_seconds' => (int) env('SAKALA_AGENT_LEASE_GRACE_SECONDS', 60),
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
