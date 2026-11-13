<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\Project;

/**
 * What a node still holds for a project. A sleep keeps the container, its
 * image, and its network on the node; only a StopProject removes them.
 */
final class ProjectWorkloadService
{
    /**
     * The project's active deployment, when a node runs it. Null without an
     * active deployment or when no node ever took it.
     */
    public function activeWorkload(Project $project): ?Deployment
    {
        if ($project->active_deployment_id === null) {
            return null;
        }

        $deployment = Deployment::query()->find($project->active_deployment_id);

        return $deployment?->agent_node_id !== null ? $deployment : null;
    }

    /**
     * The newest deployment that succeeded on a node: the workload lifecycle
     * commands act on. A command for an older one is stale.
     */
    public function currentWorkload(Project $project): ?Deployment
    {
        return Deployment::query()
            ->where('project_id', $project->id)
            ->where('status', DeploymentStatus::Succeeded)
            ->whereNotNull('agent_node_id')
            ->orderByDesc('sequence')
            ->first();
    }

    /**
     * The deployment whose container is still on a node: the one that serves,
     * crashed, or sleeps, or, when nothing serves, the newest succeeded
     * deployment if a sleep was the last thing done to it (a suspension clears
     * the pointer but keeps the container). Null when a stop already removed
     * it or no node ever ran it.
     */
    public function retainedWorkload(Project $project): ?Deployment
    {
        if ($project->active_deployment_id !== null && $project->runtime_status !== RuntimeStatus::Stopped) {
            return $this->activeWorkload($project);
        }

        $deployment = $this->currentWorkload($project);

        if ($deployment === null) {
            return null;
        }

        return $this->lastLifecycleCommand($deployment->id)?->type === AgentCommandType::SleepProject
            ? $deployment
            : null;
    }

    /**
     * Whether the active deployment's container was last put to sleep for
     * idleness. A deployment that fails or is cancelled leaves it that way, so
     * the project goes back to sleeping rather than running.
     */
    public function activeWorkloadIsAsleep(Project $project): bool
    {
        if ($project->active_deployment_id === null) {
            return false;
        }

        return $this->lastLifecycleCommand($project->active_deployment_id)?->isIdleSleep() === true;
    }

    private function lastLifecycleCommand(string $deploymentId): ?AgentCommand
    {
        return AgentCommand::query()
            ->where('deployment_id', $deploymentId)
            ->whereIn('type', AgentCommandType::projectLifecycleCases())
            ->where('status', AgentCommandStatus::Succeeded)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }
}
