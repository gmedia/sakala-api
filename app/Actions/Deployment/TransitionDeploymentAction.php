<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Actions\Admin\CreateSleepProjectCommandAction;
use App\Data\Deployment\DeploymentFailureData;
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
        private readonly CreateSleepProjectCommandAction $createSleepProjectCommandAction,
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
        $project = $deployment->project()
            ->lockForUpdate()
            ->firstOrFail();

        if ($status === DeploymentStatus::Succeeded) {
            if ($project->status === ProjectStatus::Suspended) {
                $project->update([
                    'runtime_status' => RuntimeStatus::Running,
                    'last_deployed_at' => now(),
                ]);

                $this->createSleepProjectCommandAction->handle(
                    deployment: $deployment,
                    reason: 'deployment_completed_after_suspend',
                );

                return;
            }

            $project->update([
                'runtime_status' => RuntimeStatus::Running,
                'status' => ProjectStatus::Active,
                'last_deployed_at' => now(),
            ]);

            return;
        }

        if ($status === DeploymentStatus::Failed) {
            $project->update([
                'runtime_status' => RuntimeStatus::Failed,
            ]);
        }
    }

    private function transition(
        Deployment $deployment,
        DeploymentStatus $nextStatus,
        ?DeploymentFailureData $failureData = null,
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

        if ($nextStatus === DeploymentStatus::Failed && $failureData !== null) {
            $attributes['failure_code'] = $failureData->code;
            $attributes['failure_summary'] = $failureData->summary;
        }

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
    }

    public function handleWithinTransaction(
        Deployment $deployment,
        DeploymentStatus $nextStatus,
        ?DeploymentFailureData $failureData = null,
    ): Deployment {
        return $this->transition(
            deployment: $deployment,
            nextStatus: $nextStatus,
            failureData: $failureData,
        );
    }

    public function handle(
        Deployment $deployment,
        DeploymentStatus $nextStatus,
        ?DeploymentFailureData $failureData = null,
    ): Deployment {
        return DB::transaction(function () use ($deployment, $nextStatus, $failureData) {
            return $this->transition(
                deployment: $deployment,
                nextStatus: $nextStatus,
                failureData: $failureData,
            );
        });
    }
}
