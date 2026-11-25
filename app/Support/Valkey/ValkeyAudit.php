<?php

declare(strict_types=1);

namespace App\Support\Valkey;

use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\ManagedValkey;
use App\Models\User;

/**
 * Managed Valkey changes in `audit_events`, with the store as subject
 * (ADR-023). Metadata holds IDs, mode, sizes, command IDs, node, network,
 * address, and codes; never the password or the `variables` map.
 */
final class ValkeyAudit
{
    public const CREATED = 'valkey.created';

    public const PROVISIONED = 'valkey.provisioned';

    public const PROVISIONING_FAILED = 'valkey.provisioning_failed';

    /** A repeated CreateValkey (valkeys:reconcile) completed on a store that was already ready or locked. */
    public const RECONCILED = 'valkey.reconciled';

    public const RENAMED = 'valkey.renamed';

    public const RESIZE_REQUESTED = 'valkey.resize_requested';

    public const RESIZED = 'valkey.resized';

    public const RESIZE_FAILED = 'valkey.resize_failed';

    public const DELETE_REQUESTED = 'valkey.delete_requested';

    public const DELETED = 'valkey.deleted';

    public const DELETE_FAILED = 'valkey.delete_failed';

    public const ATTACHED = 'valkey.attached';

    public const DETACHED = 'valkey.detached';

    public const CREDENTIALS_VIEWED = 'valkey.credentials_viewed';

    public const PASSWORD_ROTATION_REQUESTED = 'valkey.password_rotation_requested';

    public const PASSWORD_ROTATED = 'valkey.password_rotated';

    public const PASSWORD_ROTATION_FAILED = 'valkey.password_rotation_failed';

    /**
     * The node could not confirm a rotation, so the same new password was
     * sent again (`resent_command_id`, `attempt`).
     */
    public const PASSWORD_ROTATION_RESENT = 'valkey.password_rotation_resent';

    /** What redeploying the attached projects after a rotation did, per project. */
    public const PASSWORD_ROTATION_REDEPLOYS_QUEUED = 'valkey.password_rotation_redeploys_queued';

    public const LOCK_REQUESTED = 'valkey.lock_requested';

    public const LOCKED = 'valkey.locked';

    public const LOCK_FAILED = 'valkey.lock_failed';

    public const UNLOCK_REQUESTED = 'valkey.unlock_requested';

    public const UNLOCKED = 'valkey.unlocked';

    public const UNLOCK_FAILED = 'valkey.unlock_failed';

    /** The store passed USAGE_WARNING_PERCENT of its `maxmemory`. */
    public const MEMORY_WARNING = 'valkey.memory_warning';

    /** CreateValkey completed with a result the API cannot use (no valid network or address). */
    public const INVALID_RESULT = 'valkey.invalid_result';

    /** `reason` of a detach a user asked for. */
    public const DETACH_REASON_USER = 'user';

    /** `reason` of a detach that deleting the project did. */
    public const DETACH_REASON_PROJECT_DELETED = 'project_deleted';

    /** `actor_id` of what the usage sweep does. */
    public const USAGE_SWEEP_ACTOR = 'valkeys:check-usage';

    /** `actor_id` of an admin lock from the command line. */
    public const LOCK_ACTOR = 'valkeys:lock';

    /** `actor_id` of an admin unlock from the command line. */
    public const UNLOCK_ACTOR = 'valkeys:unlock';

    /** `actor_id` of a lock or unlock that suspending or unsuspending a project asked for. */
    public const SUSPENSION_ACTOR = 'project-suspension';

    /** `actor_id` of a reconcile from the command line. */
    public const RECONCILE_ACTOR = 'valkeys:reconcile';

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array{ip_address?: string|null, user_agent?: string|null}  $request  Only for admins reading a workspace that is not theirs.
     */
    public static function record(User|AgentNode|string|null $actor, string $action, ManagedValkey $valkey, array $metadata = [], array $request = []): void
    {
        AuditEvent::query()->create([
            ...self::actor($actor),
            'action' => $action,
            'subject_type' => ManagedValkey::class,
            'subject_id' => $valkey->id,
            'ip_address' => $request['ip_address'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'metadata' => [
                'workspace_id' => $valkey->workspace_id,
                ...$metadata,
            ],
        ]);
    }

    /**
     * A user, an agent node, or a part of the control plane named by a string.
     *
     * @return array{actor_type: string, actor_id: string|null}
     */
    private static function actor(User|AgentNode|string|null $actor): array
    {
        return match (true) {
            $actor instanceof User => ['actor_type' => User::class, 'actor_id' => (string) $actor->id],
            $actor instanceof AgentNode => ['actor_type' => AgentNode::class, 'actor_id' => $actor->id],
            default => ['actor_type' => AuditEvent::SYSTEM_ACTOR_TYPE, 'actor_id' => $actor],
        };
    }
}
