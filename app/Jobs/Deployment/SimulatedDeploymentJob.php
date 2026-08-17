<?php

declare(strict_types=1);

namespace App\Jobs\Deployment;

use App\Actions\Deployment\TransitionDeploymentAction;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SimulatedDeploymentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Deployment $deployment,
    ) {}

    public function handle(
        TransitionDeploymentAction $transition,
    ): void {
        $deployment = $this->deployment->fresh();

        if ($deployment === null) {
            return;
        }

        $statuses = [
            DeploymentStatus::Cloning,
            DeploymentStatus::Analyzing,
            DeploymentStatus::Building,
            DeploymentStatus::Deploying,
            DeploymentStatus::Routing,
            DeploymentStatus::HealthChecking,
            DeploymentStatus::Succeeded,
        ];

        foreach ($statuses as $status) {
            $deployment = $transition->handle(
                $deployment,
                $status,
            );
        }
    }
}
