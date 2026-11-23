<?php

declare(strict_types=1);

namespace App\Support\Bucket;

use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\ManagedBucket;
use App\Models\User;

/**
 * Managed bucket changes in `audit_events`, with the bucket as subject
 * (ADR-021). Metadata holds IDs, `s3_bucket`, `access_key_id`, command IDs,
 * quotas, node, region, and codes; never the secret access key or the
 * `variables` map.
 */
final class BucketAudit
{
    public const CREATED = 'bucket.created';

    public const PROVISIONED = 'bucket.provisioned';

    public const PROVISIONING_FAILED = 'bucket.provisioning_failed';

    /** A repeated CreateBucket (buckets:reconcile) completed on a bucket that was already ready or locked. */
    public const RECONCILED = 'bucket.reconciled';

    public const RENAMED = 'bucket.renamed';

    public const QUOTA_CHANGE_REQUESTED = 'bucket.quota_change_requested';

    public const QUOTA_CHANGED = 'bucket.quota_changed';

    public const QUOTA_CHANGE_FAILED = 'bucket.quota_change_failed';

    public const DELETE_REQUESTED = 'bucket.delete_requested';

    public const DELETED = 'bucket.deleted';

    public const DELETE_FAILED = 'bucket.delete_failed';

    public const ATTACHED = 'bucket.attached';

    public const DETACHED = 'bucket.detached';

    public const CREDENTIALS_VIEWED = 'bucket.credentials_viewed';

    public const KEY_ROTATION_REQUESTED = 'bucket.key_rotation_requested';

    public const KEY_ROTATED = 'bucket.key_rotated';

    public const KEY_ROTATION_FAILED = 'bucket.key_rotation_failed';

    /**
     * The node could not confirm a rotation, so the same new secret was sent
     * again (`resent_command_id`, `attempt`).
     */
    public const KEY_ROTATION_RESENT = 'bucket.key_rotation_resent';

    /** What redeploying the attached projects after a rotation did, per project. */
    public const KEY_ROTATION_REDEPLOYS_QUEUED = 'bucket.key_rotation_redeploys_queued';

    public const LOCK_REQUESTED = 'bucket.lock_requested';

    public const LOCKED = 'bucket.locked';

    public const LOCK_FAILED = 'bucket.lock_failed';

    public const UNLOCK_REQUESTED = 'bucket.unlock_requested';

    public const UNLOCKED = 'bucket.unlocked';

    public const UNLOCK_FAILED = 'bucket.unlock_failed';

    /** The bucket passed USAGE_WARNING_PERCENT of its quota. */
    public const STORAGE_WARNING = 'bucket.storage_warning';

    /** `reason` of a detach a user asked for. */
    public const DETACH_REASON_USER = 'user';

    /** `reason` of a detach that deleting the project did. */
    public const DETACH_REASON_PROJECT_DELETED = 'project_deleted';

    /** `actor_id` of what the usage sweep does. */
    public const USAGE_SWEEP_ACTOR = 'buckets:check-usage';

    /** `actor_id` of an admin lock from the command line. */
    public const LOCK_ACTOR = 'buckets:lock';

    /** `actor_id` of an admin unlock from the command line. */
    public const UNLOCK_ACTOR = 'buckets:unlock';

    /** `actor_id` of a reconcile from the command line. */
    public const RECONCILE_ACTOR = 'buckets:reconcile';

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array{ip_address?: string|null, user_agent?: string|null}  $request  Only for admins reading a workspace that is not theirs.
     */
    public static function record(User|AgentNode|string|null $actor, string $action, ManagedBucket $bucket, array $metadata = [], array $request = []): void
    {
        AuditEvent::query()->create([
            ...self::actor($actor),
            'action' => $action,
            'subject_type' => ManagedBucket::class,
            'subject_id' => $bucket->id,
            'ip_address' => $request['ip_address'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'metadata' => [
                'workspace_id' => $bucket->workspace_id,
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
