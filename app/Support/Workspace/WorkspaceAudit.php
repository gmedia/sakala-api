<?php

declare(strict_types=1);

namespace App\Support\Workspace;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;

/**
 * Membership, role, invitation, and limit profile changes, recorded with IDs
 * and roles only: no email address or name lands in the audit trail.
 */
final class WorkspaceAudit
{
    /** @param array<string, mixed> $metadata */
    public static function record(?User $actor, string $action, Workspace $workspace, array $metadata = []): void
    {
        AuditEvent::query()->create([
            'actor_type' => $actor !== null ? User::class : 'system',
            'actor_id' => $actor !== null ? (string) $actor->id : null,
            'action' => $action,
            'subject_type' => Workspace::class,
            'subject_id' => $workspace->id,
            'metadata' => $metadata,
        ]);
    }
}
