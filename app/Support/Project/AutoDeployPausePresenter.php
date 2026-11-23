<?php

declare(strict_types=1);

namespace App\Support\Project;

use App\Data\GitHub\SkippedPushData;
use App\Enums\AutoDeployPauseReason;
use App\Enums\PushSkipReason;
use App\Models\AuditEvent;
use App\Models\Project;

/**
 * `auto_deploy_pause` of ProjectResource: why and since when auto-deploy is
 * paused after a rollback, and how many pushes it has held since. One count
 * per request, served by the `(subject_type, subject_id, created_at)` index of
 * `audit_events`; the project list does not carry it.
 */
final class AutoDeployPausePresenter
{
    /**
     * @return array{paused_at: string, reason: AutoDeployPauseReason, deployment_id: string|null, deployment_sequence: int|null, held_push_count: int}|null
     */
    public static function present(Project $project): ?array
    {
        $pausedAt = $project->auto_deploy_paused_at;

        if ($pausedAt === null) {
            return null;
        }

        $deploymentId = $project->auto_deploy_paused_by_deployment_id;

        return [
            'paused_at' => $pausedAt->toAtomString(),
            'reason' => AutoDeployPauseReason::Rollback,
            'deployment_id' => $deploymentId,
            'deployment_sequence' => $deploymentId === null ? null : $project->loadMissing('autoDeployPausedBy')->autoDeployPausedBy?->sequence,
            'held_push_count' => AuditEvent::query()
                ->where('subject_type', Project::class)
                ->where('subject_id', $project->id)
                ->where('created_at', '>=', $pausedAt)
                ->where('action', SkippedPushData::AUDIT_ACTION)
                ->where('metadata->reason', PushSkipReason::AutoDeployPaused->value)
                ->count(),
        ];
    }
}
