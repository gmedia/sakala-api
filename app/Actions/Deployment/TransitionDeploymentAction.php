<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use InvalidArgumentException;

final class TransitionDeploymentAction
{
    private function canTransition(DeploymentStatus $current, DeploymentStatus $next): bool
    {
        return match ($current) {
            DeploymentStatus::Queued => $next === DeploymentStatus::Cloning,
            DeploymentStatus::Cloning => $next === DeploymentStatus::Analyzing,
            DeploymentStatus::Analyzing => $next === DeploymentStatus::Building,
            DeploymentStatus::Building => $next === DeploymentStatus::Deploying,
            DeploymentStatus::Deploying => $next === DeploymentStatus::Routing,
            DeploymentStatus::Routing => $next === DeploymentStatus::HealthChecking,
            DeploymentStatus::HealthChecking => in_array($next, [
                DeploymentStatus::Succeeded,
                DeploymentStatus::Failed,
            ], true),

            default => false,
        };
    }

    public function handle(
        Deployment $deployment,
        DeploymentStatus $nextStatus
    ): Deployment {
        /** @var DeploymentStatus $currentStatus */
        $currentStatus = $deployment->status;

        if (! $this->canTransition($currentStatus, $nextStatus)) {
            throw new InvalidArgumentException("Cannot transition from {$currentStatus->value} to {$nextStatus->value}");
        }

        $deployment->update([
            'status' => $nextStatus,
        ]);

        return $deployment->refresh();
    }
}
