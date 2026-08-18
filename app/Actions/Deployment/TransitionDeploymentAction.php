<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Enums\DeploymentEventLevel;
use App\Enums\DeploymentStatus;
use App\Enums\LogStream;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\Deployment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TransitionDeploymentAction
{
    public function __construct(
        private readonly CreateDeploymentEventAction $createDeploymentEventAction,
        private readonly CreateDeploymentLogAction $createDeploymentLogAction,
    ) {}

    private function canTransition(
        DeploymentStatus $current,
        DeploymentStatus $next,
    ): bool {
        if ($current === DeploymentStatus::HealthChecking) {
            return $next === DeploymentStatus::Succeeded
                || $next === DeploymentStatus::Failed
                || $next === DeploymentStatus::Cancelled;
        }

        return match ($current) {
            DeploymentStatus::Queued => $next === DeploymentStatus::Cloning,
            DeploymentStatus::Cloning => $next === DeploymentStatus::Analyzing,
            DeploymentStatus::Analyzing => $next === DeploymentStatus::Building,
            DeploymentStatus::Building => $next === DeploymentStatus::Deploying,
            DeploymentStatus::Deploying => $next === DeploymentStatus::Routing,
            DeploymentStatus::Routing => $next === DeploymentStatus::HealthChecking,
            default => false,
        };
    }

    private function messageFor(DeploymentStatus $status): string
    {
        return match ($status) {
            DeploymentStatus::Queued => 'Deployment is queued.',
            DeploymentStatus::Cloning => 'Cloning the repository.',
            DeploymentStatus::Analyzing => 'Analyzing the codebase.',
            DeploymentStatus::Building => 'Building the application.',
            DeploymentStatus::Deploying => 'Deploying the application.',
            DeploymentStatus::Routing => 'Configuring routing.',
            DeploymentStatus::HealthChecking => 'Performing health checks.',
            DeploymentStatus::Succeeded => 'Deployment succeeded.',
            DeploymentStatus::Failed => 'Deployment failed.',
            DeploymentStatus::Cancelled => 'Deployment was cancelled.',
        };
    }

    private function updateProjectRuntime(
        Deployment $deployment,
        DeploymentStatus $status,
    ): void {
        $attributes = match ($status) {
            DeploymentStatus::Succeeded => [
                'status' => ProjectStatus::Active,
                'runtime_status' => RuntimeStatus::Running,
                'last_deployed_at' => now(),
            ],

            DeploymentStatus::Failed => [
                'runtime_status' => RuntimeStatus::Failed,
            ],

            default => [],
        };

        if ($attributes === []) {
            return;
        }

        $deployment->project()->update($attributes);
    }

    public function handle(
        Deployment $deployment,
        DeploymentStatus $nextStatus,
    ): Deployment {
        return DB::transaction(function () use (
            $deployment,
            $nextStatus,
        ): Deployment {
            $deployment = Deployment::query()
                ->whereKey($deployment->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var DeploymentStatus $currentStatus */
            $currentStatus = $deployment->status;
            if (! $this->canTransition($currentStatus, $nextStatus)) {
                throw new InvalidArgumentException(
                    "Cannot transition from {$currentStatus->value} to {$nextStatus->value}",
                );
            }

            $attributes = [
                'status' => $nextStatus,
            ];

            if ($currentStatus === DeploymentStatus::Queued && $nextStatus === DeploymentStatus::Cloning) {
                $attributes['started_at'] = now();
            }

            if ($nextStatus->isTerminal()) {
                $attributes['finished_at'] = now();
            }

            $deployment->update($attributes);

            $message = $this->messageFor($nextStatus);

            $this->createDeploymentEventAction->handle(
                deployment: $deployment,
                level: DeploymentEventLevel::Info,
                type: "deployment.{$nextStatus->value}",
                message: $message,
            );

            $this->createDeploymentLogAction->handle(
                deployment: $deployment,
                logStream: LogStream::Stdout,
                message: $message,
            );

            $this->updateProjectRuntime(
                deployment: $deployment,
                status: $nextStatus
            );

            return $deployment->refresh();
        });
    }
}
