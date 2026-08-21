<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Enums\DeploymentEventLevel;
use App\Enums\DeploymentStatus;
use App\Enums\LogStream;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\Deployment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TransitionDeploymentAction
{
    public function __construct(
        private readonly CreateDeploymentEventAction $createDeploymentEventAction,
        private readonly CreateDeploymentLogAction $createDeploymentLogAction,
        private readonly AllocateDeploymentRealtimeSequenceAction $allocateDeploymentRealtimeSequenceAction,
    ) {}

    private function canTransition(
        DeploymentStatus $current,
        DeploymentStatus $next,
    ): bool {
        if ($next === DeploymentStatus::Failed) {
            return ! $current->isTerminal();
        }

        return match ($current) {
            DeploymentStatus::Queued => $next === DeploymentStatus::Cloning,
            DeploymentStatus::Cloning => $next === DeploymentStatus::Analyzing,
            DeploymentStatus::Analyzing => $next === DeploymentStatus::Building,
            DeploymentStatus::Building => $next === DeploymentStatus::Deploying,
            DeploymentStatus::Deploying => $next === DeploymentStatus::Routing,
            DeploymentStatus::Routing => $next === DeploymentStatus::HealthChecking,

            DeploymentStatus::HealthChecking => in_array($next, [
                DeploymentStatus::Succeeded,
                DeploymentStatus::Cancelled,
            ], true),

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

    private function eventLevel(
        DeploymentStatus $status,
    ): DeploymentEventLevel {
        return match ($status) {
            DeploymentStatus::Failed => DeploymentEventLevel::Error,
            DeploymentStatus::Cancelled => DeploymentEventLevel::Warning,
            default => DeploymentEventLevel::Info,
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

        $deployment->project()
            ->lockForUpdate()
            ->update($attributes);
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

            if ($nextStatus === DeploymentStatus::Cancelled) {
                $attributes['cancelled_at'] = now();
            }

            $deployment->update($attributes);

            $realtimeSequence = $this->allocateDeploymentRealtimeSequenceAction->handle($deployment);

            DeploymentUpdated::dispatch(
                deploymentId: $deployment->id,
                payload: [
                    'deployment_id' => $deployment->id,
                    'project_id' => $deployment->project_id,
                    'sequence' => $realtimeSequence,
                    'status' => $deployment->status->value,
                    'trigger' => $deployment->trigger->value,
                    'branch' => $deployment->branch,
                    'commit_sha' => $deployment->commit_sha,
                    'commit_message' => $deployment->commit_message,
                    'started_at' => $deployment->started_at?->toISOString(),
                    'finished_at' => $deployment->finished_at?->toISOString(),
                ]
            );

            $message = $this->messageFor($nextStatus);

            $this->createDeploymentEventAction->handle(
                deployment: $deployment,
                level: $this->eventLevel($nextStatus),
                type: "deployment.{$nextStatus->value}",
                message: $message,
            );

            $this->createDeploymentLogAction->handle(
                deployment: $deployment,
                logStream: $nextStatus === DeploymentStatus::Failed
                    ? LogStream::Stderr
                    : LogStream::Stdout,
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
