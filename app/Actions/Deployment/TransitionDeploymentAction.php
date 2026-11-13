<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Actions\Admin\CreateSleepProjectCommandAction;
use App\Actions\Admin\CreateStopProjectCommandAction;
use App\Data\Deployment\DeploymentFailureData;
use App\Enums\DeploymentEventLevel;
use App\Enums\DeploymentStatus;
use App\Enums\LogStream;
use App\Enums\NotificationCategory;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Notification\UserNotifier;
use App\Services\Runtime\ProjectWorkloadService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TransitionDeploymentAction
{
    public function __construct(
        private readonly CreateDeploymentEventAction $createDeploymentEventAction,
        private readonly CreateDeploymentLogAction $createDeploymentLogAction,
        private readonly AllocateDeploymentRealtimeSequenceAction $allocateDeploymentRealtimeSequenceAction,
        private readonly CreateSleepProjectCommandAction $createSleepProjectCommandAction,
        private readonly CreateStopProjectCommandAction $createStopProjectCommandAction,
        private readonly UserNotifier $notifier,
        private readonly ProjectWorkloadService $workloads,
    ) {}

    /**
     * Forward-only lifecycle. Any active deployment may end in a terminal
     * state (the agent's completion or failure is authoritative and does not
     * replay every phase); otherwise the next phase must be strictly later
     * than the current one.
     */
    private function canTransition(
        DeploymentStatus $current,
        DeploymentStatus $next,
    ): bool {
        if ($current->isTerminal()) {
            return false;
        }

        if ($next->isTerminal()) {
            return true;
        }

        return $next->order() > $current->order();
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
        ?DeploymentFailureData $failureData = null,
    ): void {
        // A deleted project can still have an attempt in flight; its completion
        // must be recorded so the workload it started gets stopped.
        $project = $deployment->project()
            ->withTrashed()
            ->lockForUpdate()
            ->firstOrFail();

        if ($status === DeploymentStatus::Succeeded) {
            $activeDeployment = $this->supersedesActiveDeployment($project, $deployment)
                ? ['active_deployment_id' => $deployment->id]
                : [];

            if ($project->trashed()) {
                $project->update(['runtime_status' => RuntimeStatus::Running, ...$activeDeployment]);

                if ($deployment->agent_node_id !== null) {
                    $this->createStopProjectCommandAction->handle(
                        deployment: $deployment,
                        reason: 'project_deleted',
                        idempotencyKey: "project-delete:{$project->id}:{$deployment->id}",
                        removeImages: true,
                    );
                }

                return;
            }

            $this->notifyOwner(
                $project,
                $deployment,
                NotificationCategory::DeploymentSuccess,
                'Deployment berhasil',
                "{$project->name} sudah live dengan commit ".mb_substr((string) $deployment->commit_sha, 0, 7).'.',
            );

            if ($project->status === ProjectStatus::Suspended) {
                $project->update([
                    'runtime_status' => RuntimeStatus::Running,
                    'last_deployed_at' => now(),
                    ...$activeDeployment,
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
                ...$activeDeployment,
            ]);

            return;
        }

        if ($status === DeploymentStatus::Failed) {
            // The agent only cuts over after a healthy start, so a failed
            // attempt leaves the previous deployment serving. `failed` is
            // reserved for projects with nothing live; a known crash of the
            // live workload stays visible until a heartbeat clears it.
            // A sleeping version stays asleep: the attempt never woke it.
            $project->update([
                'runtime_status' => match (true) {
                    $project->active_deployment_id === null => RuntimeStatus::Failed,
                    $project->runtime_status === RuntimeStatus::Crashed => RuntimeStatus::Crashed,
                    $project->runtime_status === RuntimeStatus::Sleeping,
                    $this->workloads->activeWorkloadIsAsleep($project) => RuntimeStatus::Sleeping,
                    default => RuntimeStatus::Running,
                },
            ]);

            if (! $project->trashed()) {
                $this->notifyOwner(
                    $project,
                    $deployment,
                    NotificationCategory::DeploymentFailure,
                    'Deployment gagal',
                    $failureData !== null
                        ? "{$project->name}: {$failureData->summary}"
                        : "Deployment {$project->name} gagal. Buka log deployment untuk melihat penyebabnya.",
                );
            }

            return;
        }

        if ($status === DeploymentStatus::Cancelled) {
            // Only undo what this attempt did; a queued attempt never changed
            // the status, and a crash of the live workload stays visible.
            if ($project->runtime_status === RuntimeStatus::Deploying) {
                $project->update([
                    'runtime_status' => match (true) {
                        $this->workloads->activeWorkloadIsAsleep($project) => RuntimeStatus::Sleeping,
                        $project->active_deployment_id !== null => RuntimeStatus::Running,
                        $project->last_deployed_at !== null => RuntimeStatus::Stopped,
                        default => RuntimeStatus::NotDeployed,
                    },
                ]);
            }

            return;
        }

        if ($status->isActive() && $status !== DeploymentStatus::Queued) {
            $project->update([
                'runtime_status' => RuntimeStatus::Deploying,
            ]);
        }
    }

    /**
     * The highest succeeded sequence is the current workload everywhere else
     * in the control plane; a late completion of an older attempt must not
     * move the pointer backwards.
     */
    private function supersedesActiveDeployment(Project $project, Deployment $deployment): bool
    {
        if ($project->active_deployment_id === null) {
            return true;
        }

        $activeSequence = Deployment::query()
            ->whereKey($project->active_deployment_id)
            ->value('sequence');

        return $activeSequence === null || (int) $activeSequence <= $deployment->sequence;
    }

    private function notifyOwner(
        Project $project,
        Deployment $deployment,
        NotificationCategory $category,
        string $title,
        string $message,
    ): void {
        // The person who asked for the deployment hears about it while they can
        // still act on the project; otherwise everyone who can.
        $this->notifier->sendToProject($project, $category, $title, $message, $deployment->id, preferred: $deployment->requester);
    }

    private function transition(
        Deployment $deployment,
        DeploymentStatus $nextStatus,
        ?DeploymentFailureData $failureData = null,
        bool $recordTimeline = true,
        ?string $failureDetail = null,
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

        $endedBadly = in_array($nextStatus, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true);

        if ($endedBadly) {
            // Persisted on the deployment because the timeline this could be
            // derived from is pruned after the retention window.
            $attributes['failure_stage'] = $currentStatus;
        }

        if ($endedBadly && $failureData !== null) {
            $attributes['failure_code'] = $failureData->code;
            $attributes['failure_summary'] = $failureData->summary;
            $attributes['failure_detail'] = $failureDetail === null || $failureDetail === ''
                ? null
                : mb_substr($failureDetail, 0, Deployment::MAX_FAILURE_DETAIL_LENGTH);
        }

        // Every transition leaves Queued behind (forward-only), so the first
        // one marks the start; later ones never overwrite it.
        if ($deployment->started_at === null) {
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
            payload: DeploymentUpdated::payloadFor($deployment, $realtimeSequence),
        );

        // When the agent drives the lifecycle it has already reported the
        // timeline; only the simulated path needs synthetic events and logs.
        if ($recordTimeline) {
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
        }

        $this->updateProjectRuntime(
            deployment: $deployment,
            status: $nextStatus,
            failureData: $failureData,
        );

        return $deployment->refresh();
    }

    public function handleWithinTransaction(
        Deployment $deployment,
        DeploymentStatus $nextStatus,
        ?DeploymentFailureData $failureData = null,
        bool $recordTimeline = true,
        ?string $failureDetail = null,
    ): Deployment {
        return $this->transition(
            deployment: $deployment,
            nextStatus: $nextStatus,
            failureData: $failureData,
            recordTimeline: $recordTimeline,
            failureDetail: $failureDetail,
        );
    }

    public function handle(
        Deployment $deployment,
        DeploymentStatus $nextStatus,
        ?DeploymentFailureData $failureData = null,
        bool $recordTimeline = true,
    ): Deployment {
        return DB::transaction(function () use ($deployment, $nextStatus, $failureData, $recordTimeline) {
            return $this->transition(
                deployment: $deployment,
                nextStatus: $nextStatus,
                failureData: $failureData,
                recordTimeline: $recordTimeline,
            );
        });
    }
}
