<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;

/**
 * What a rollback does besides creating its deployment, in the same
 * transaction and under the same project lock (CreateDeploymentAction):
 *
 * - pause auto-deploy, so the next push does not undo the rollback. A project
 *   whose auto-deploy is on is paused by this rollback; one that is already
 *   paused keeps its `auto_deploy_paused_at` (held pushes keep counting) and
 *   the pause moves to this rollback; one whose auto-deploy was off stays as
 *   it is, unpaused;
 * - audit `deployment.rollback_requested`.
 */
final class RecordRollbackAction
{
    public const AUDIT_ACTION = 'deployment.rollback_requested';

    public function handle(Project $lockedProject, Deployment $rollback, Deployment $target, User $user): void
    {
        $paused = $lockedProject->auto_deploy || $lockedProject->isAutoDeployPaused();

        if ($paused) {
            $lockedProject->update([
                'auto_deploy' => false,
                'auto_deploy_paused_at' => $lockedProject->auto_deploy_paused_at ?? now(),
                'auto_deploy_paused_by_deployment_id' => $rollback->id,
            ]);
        }

        AuditEvent::create([
            'actor_type' => User::class,
            'actor_id' => (string) $user->id,
            'action' => self::AUDIT_ACTION,
            'subject_type' => Deployment::class,
            'subject_id' => $rollback->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => [
                'project_id' => $lockedProject->id,
                'target_deployment_id' => $target->id,
                'target_sequence' => $target->sequence,
                'build_mode' => $rollback->build_mode->value,
                'auto_deploy_paused' => $paused,
            ],
        ]);
    }
}
