<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Models\Workspace;

/**
 * Managed database changes in `audit_events`, with the database as subject.
 * Metadata holds IDs, names on the node, and codes, never a password or the
 * connection URL.
 */
final class DatabaseAudit
{
    public const CREATED = 'database.created';

    public const PROVISIONED = 'database.provisioned';

    public const PROVISIONING_FAILED = 'database.provisioning_failed';

    public const RENAMED = 'database.renamed';

    public const DELETE_REQUESTED = 'database.delete_requested';

    public const DELETED = 'database.deleted';

    public const DROP_FAILED = 'database.drop_failed';

    public const ATTACHED = 'database.attached';

    public const DETACHED = 'database.detached';

    /** A database created for a project with `deploy_when_ready`: deploy the project once the database is ready. */
    public const PROJECT_DEPLOY_REQUESTED = 'database.project_deploy_requested';

    /** That deployment was created (`deployment_id`). */
    public const PROJECT_DEPLOY_STARTED = 'database.project_deploy_started';

    /** That deployment was not created (`reason`, a DatabaseRedeploySkipReason value). */
    public const PROJECT_DEPLOY_SKIPPED = 'database.project_deploy_skipped';

    public const CREDENTIALS_VIEWED = 'database.credentials_viewed';

    public const PASSWORD_ROTATION_REQUESTED = 'database.password_rotation_requested';

    public const PASSWORD_ROTATED = 'database.password_rotated';

    public const PASSWORD_ROTATION_FAILED = 'database.password_rotation_failed';

    /** What redeploying the attached projects after a rotation did, per project. */
    public const PASSWORD_ROTATION_REDEPLOYS_QUEUED = 'database.password_rotation_redeploys_queued';

    public const LOCK_REQUESTED = 'database.lock_requested';

    public const LOCKED = 'database.locked';

    public const UNLOCK_REQUESTED = 'database.unlock_requested';

    public const UNLOCKED = 'database.unlocked';

    public const LOCK_FAILED = 'database.lock_failed';

    public const UNLOCK_FAILED = 'database.unlock_failed';

    public const EXTENSION_INSTALL_REQUESTED = 'database.extension_install_requested';

    public const EXTENSION_INSTALLED = 'database.extension_installed';

    public const EXTENSION_INSTALL_FAILED = 'database.extension_install_failed';

    /** Subject is the workspace: its databases passed the warning share of the storage quota. */
    public const STORAGE_WARNING = 'workspace.database_storage_warning';

    /** `actor_id` of what the storage quota sweep does. */
    public const STORAGE_SWEEP_ACTOR = 'databases:enforce-storage-quota';

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array{ip_address?: string|null, user_agent?: string|null}  $request  Only for admins reading a workspace that is not theirs.
     */
    public static function record(User|AgentNode|string|null $actor, string $action, ManagedDatabase $database, array $metadata = [], array $request = []): void
    {
        AuditEvent::query()->create([
            ...self::actor($actor),
            'action' => $action,
            'subject_type' => ManagedDatabase::class,
            'subject_id' => $database->id,
            'ip_address' => $request['ip_address'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'metadata' => [
                'workspace_id' => $database->workspace_id,
                ...$metadata,
            ],
        ]);
    }

    /** @param  array<string, mixed>  $metadata */
    public static function recordForWorkspace(string $action, Workspace $workspace, array $metadata = []): void
    {
        AuditEvent::query()->create([
            ...self::actor(self::STORAGE_SWEEP_ACTOR),
            'action' => $action,
            'subject_type' => Workspace::class,
            'subject_id' => $workspace->id,
            'metadata' => $metadata,
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
