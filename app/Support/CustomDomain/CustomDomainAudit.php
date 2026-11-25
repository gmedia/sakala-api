<?php

declare(strict_types=1);

namespace App\Support\CustomDomain;

use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\ProjectDomain;
use App\Models\User;

/**
 * Custom domain changes in `audit_events`, with the claim as subject
 * (ADR-024). Metadata holds the project, the hostname, statuses, and
 * reasons; the token is public in DNS but is still never written here.
 */
final class CustomDomainAudit
{
    public const CREATED = 'project_domain.created';

    public const VERIFIED = 'project_domain.verified';

    /** A pending claim did not pass within the pending window. */
    public const FAILED = 'project_domain.failed';

    /** A verified domain's first failing check since its last pass. */
    public const FAILING = 'project_domain.failing';

    /** The warning email of a failing run went out. */
    public const WARNED = 'project_domain.warned';

    public const DISABLED = 'project_domain.disabled';

    public const REACTIVATED = 'project_domain.reactivated';

    /** Another project verified the name of this disabled claim. */
    public const RELEASED = 'project_domain.released';

    public const DELETED = 'project_domain.deleted';

    public const ADMIN_DISABLED = 'project_domain.admin_disabled';

    public const ADMIN_ENABLED = 'project_domain.admin_enabled';

    /** A node refused UpdateProjectDomains (`error_code`). */
    public const ROUTE_UPDATE_FAILED = 'project_domain.route_update_failed';

    /** `actor_id` of what custom-domains:check does. */
    public const CHECK_ACTOR = 'custom-domains:check';

    /** `actor_id` of what deleting the project does. */
    public const PROJECT_DELETE_ACTOR = 'project-delete';

    /** `reason` of a deletion that deleting the project did. */
    public const DELETE_REASON_PROJECT_DELETED = 'project_deleted';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(User|AgentNode|string|null $actor, string $action, ProjectDomain $domain, array $metadata = []): void
    {
        AuditEvent::query()->create([
            ...self::actor($actor),
            'action' => $action,
            'subject_type' => ProjectDomain::class,
            'subject_id' => $domain->id,
            'metadata' => [
                'project_id' => $domain->project_id,
                'hostname' => $domain->hostname,
                'status' => $domain->status->value,
                ...$metadata,
            ],
        ]);
    }

    /**
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
