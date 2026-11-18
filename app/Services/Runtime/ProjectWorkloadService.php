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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * What a node still holds for a project. A sleep keeps the container, its
 * image, and its network on the node; only a StopProject removes them.
 */
final class ProjectWorkloadService
{
    /** Attribute retainedWorkloads() adds: the type of the last lifecycle command that succeeded. */
    private const LAST_LIFECYCLE_TYPE_ATTRIBUTE = 'last_lifecycle_type';

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
     * retainedWorkload() of every project that is not deleted, in two
     * queries whatever the number of projects. Keyed by project ID; a project
     * without a retained workload is absent. A deleted project is left out
     * even while its stop is still on the way: nobody should reach it anymore.
     *
     * @return Collection<string, Deployment>
     */
    public function retainedWorkloads(): Collection
    {
        // A project whose active deployment a node runs, and that is not
        // stopped: it serves, crashed, failed a redeploy, or sleeps.
        /** @var Collection<int, Deployment> $active */
        $active = Deployment::query()
            ->whereNotNull('agent_node_id')
            ->whereIn('id', Project::query()
                ->select('active_deployment_id')
                ->whereNotNull('active_deployment_id')
                ->where('runtime_status', '!=', RuntimeStatus::Stopped))
            ->get();

        // Otherwise the newest succeeded deployment on a node, when a sleep
        // was the last lifecycle command that succeeded on it (a suspension
        // clears the pointer but keeps the container).
        /** @var Collection<int, Deployment> $kept */
        $kept = Deployment::query()
            ->select('deployments.*')
            ->addSelect([self::LAST_LIFECYCLE_TYPE_ATTRIBUTE => AgentCommand::query()
                ->select('type')
                ->whereColumn('agent_commands.deployment_id', 'deployments.id')
                ->whereIn('type', AgentCommandType::projectLifecycleCases())
                ->where('status', AgentCommandStatus::Succeeded)
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->limit(1)])
            ->where('status', DeploymentStatus::Succeeded)
            ->whereNotNull('agent_node_id')
            ->whereIn('project_id', Project::query()
                ->select('id')
                ->where(fn (Builder $query) => $query
                    ->whereNull('active_deployment_id')
                    ->orWhere('runtime_status', RuntimeStatus::Stopped)))
            ->whereNotExists(fn (QueryBuilder $newer) => $newer
                ->selectRaw('1')
                ->from('deployments as newer')
                ->whereColumn('newer.project_id', 'deployments.project_id')
                ->whereColumn('newer.sequence', '>', 'deployments.sequence')
                ->where('newer.status', DeploymentStatus::Succeeded->value)
                ->whereNotNull('newer.agent_node_id'))
            ->get()
            ->filter(static fn (Deployment $deployment): bool => $deployment->getAttribute(self::LAST_LIFECYCLE_TYPE_ATTRIBUTE) === AgentCommandType::SleepProject->value);

        return $active->concat($kept)->keyBy('project_id');
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
