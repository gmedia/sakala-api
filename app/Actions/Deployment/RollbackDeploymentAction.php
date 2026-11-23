<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Data\Deployment\CreateDeploymentData;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;

/**
 * Go back to an earlier successful deployment of the project: a new
 * deployment (`trigger = rollback`) with the target's commit and recorded
 * settings, the project's current variables, resources, replicas, and domain,
 * and the target's image when its node still has it. Everything else is a
 * normal deployment's (CreateDeploymentAction): the same locks, limits,
 * idempotency, and errors, with the target checked under the project lock
 * (DeploymentRollbackEligibility). It also pauses auto-deploy
 * (RecordRollbackAction).
 */
final class RollbackDeploymentAction
{
    public function __construct(
        private readonly CreateDeploymentAction $createDeployment,
    ) {}

    public function handle(Project $project, Deployment $target, User $user, ?string $idempotencyKey): Deployment
    {
        return $this->createDeployment->handle(
            project: $project,
            user: $user,
            data: CreateDeploymentData::rollback($target, $idempotencyKey),
        );
    }
}
