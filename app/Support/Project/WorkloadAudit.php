<?php

declare(strict_types=1);

namespace App\Support\Project;

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\User;

/**
 * Changes to a project's worker processes and schedules in `audit_events`
 * (ADR-022), with the project as subject. Metadata holds IDs, names, and the
 * changed fields; never the project's variables.
 */
final class WorkloadAudit
{
    public const PROCESS_CREATED = 'project.process_created';

    public const PROCESS_UPDATED = 'project.process_updated';

    public const PROCESS_DELETED = 'project.process_deleted';

    public const SCHEDULE_CREATED = 'project.schedule_created';

    public const SCHEDULE_UPDATED = 'project.schedule_updated';

    public const SCHEDULE_DELETED = 'project.schedule_deleted';

    /** "Run now" by a member. */
    public const SCHEDULE_RUN_REQUESTED = 'project.schedule_run_requested';

    /** Stop run by a member. */
    public const SCHEDULE_RUN_CANCEL_REQUESTED = 'project.schedule_run_cancel_requested';

    /** The control plane disabled a schedule after too many failures in a row. */
    public const SCHEDULE_PAUSED = 'project.schedule_paused';

    /** `actor_id` of what `schedules:dispatch` and run outcomes record. */
    public const SCHEDULER_ACTOR = 'schedules:dispatch';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(User|string $actor, string $action, Project $project, array $metadata = []): void
    {
        $request = $actor instanceof User ? request() : null;

        AuditEvent::query()->create([
            'actor_type' => $actor instanceof User ? User::class : AuditEvent::SYSTEM_ACTOR_TYPE,
            'actor_id' => $actor instanceof User ? (string) $actor->id : $actor,
            'action' => $action,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => [
                'workspace_id' => $project->workspace_id,
                ...$metadata,
            ],
        ]);
    }
}
