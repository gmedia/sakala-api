<?php

declare(strict_types=1);

namespace App\Support\Admin;

/**
 * Columns an admin may read from `agent_commands`. `payload` (which carries
 * encrypted environment variables), `request_context`, `response_context`,
 * `result`, and `idempotency_key` are never selected.
 */
final class AgentCommandColumns
{
    public const SAFE = [
        'id',
        'project_id',
        'deployment_id',
        'agent_node_id',
        'type',
        'status',
        'attempts',
        'error_code',
        'error_message',
        'available_at',
        'claimed_at',
        'started_at',
        'completed_at',
        'failed_at',
        'expires_at',
        'lease_expires_at',
        'cancel_requested_at',
        'created_at',
        'updated_at',
    ];
}
