<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Data\Runtime\EffectiveRuntimeLimitsData;
use App\Data\Runtime\LogBoundsData;
use App\Data\Runtime\PilotQuotaLimitsData;
use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Data\Runtime\RuntimeTimeoutLimitsData;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\ProjectLimitExceededException;
use App\Exceptions\Runtime\ResourceLimitExceededException;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;

class PilotRuntimeLimitService
{
    /**
     * Resolve effective runtime limits based on requested limits and pilot configuration.
     *
     * @throws ResourceLimitExceededException
     */
    public function resolveEffectiveLimits(
        ?RuntimeResourceLimitsData $requested = null,
        ?User $user = null,
    ): EffectiveRuntimeLimitsData {
        $defaultMemory = (int) config('sakala.pilot_limits.resources.default_memory_mb', 256);
        $maxMemory = (int) config('sakala.pilot_limits.resources.max_memory_mb', 512);

        $defaultCpu = (int) config('sakala.pilot_limits.resources.default_cpu_millis', 500);
        $maxCpu = (int) config('sakala.pilot_limits.resources.max_cpu_millis', 1000);

        $defaultPids = (int) config('sakala.pilot_limits.resources.default_pids_limit', 128);
        $maxPids = (int) config('sakala.pilot_limits.resources.max_pids_limit', 256);

        $memory = $defaultMemory;
        if ($requested?->memory_mb !== null) {
            if ($requested->memory_mb <= 0 || $requested->memory_mb > $maxMemory) {
                throw new ResourceLimitExceededException('memory_mb', $requested->memory_mb, $maxMemory);
            }
            $memory = $requested->memory_mb;
        }

        $cpu = $defaultCpu;
        if ($requested?->cpu_millis !== null) {
            if ($requested->cpu_millis <= 0 || $requested->cpu_millis > $maxCpu) {
                throw new ResourceLimitExceededException('cpu_millis', $requested->cpu_millis, $maxCpu);
            }
            $cpu = $requested->cpu_millis;
        }

        $pids = $defaultPids;
        if ($requested?->pids_limit !== null) {
            if ($requested->pids_limit <= 0 || $requested->pids_limit > $maxPids) {
                throw new ResourceLimitExceededException('pids_limit', $requested->pids_limit, $maxPids);
            }
            $pids = $requested->pids_limit;
        }

        $timeouts = new RuntimeTimeoutLimitsData(
            build_timeout_seconds: (int) config('sakala.pilot_limits.timeouts.build_timeout_seconds', 600),
            start_timeout_seconds: (int) config('sakala.pilot_limits.timeouts.start_timeout_seconds', 120),
            command_timeout_seconds: (int) config('sakala.pilot_limits.timeouts.command_timeout_seconds', 900),
        );

        $logBounds = new LogBoundsData(
            max_line_length: (int) config('sakala.pilot_limits.log_bounds.max_line_length', 4096),
            max_batch_lines: (int) config('sakala.pilot_limits.log_bounds.max_batch_lines', 500),
            max_total_bytes: (int) config('sakala.pilot_limits.log_bounds.max_total_bytes', 10 * 1024 * 1024),
        );

        return new EffectiveRuntimeLimitsData(
            memory_mb: $memory,
            cpu_millis: $cpu,
            pids_limit: $pids,
            timeouts: $timeouts,
            log_bounds: $logBounds,
        );
    }

    /**
     * Check whether a user is allowed to create another project under pilot quota.
     *
     * @throws ProjectLimitExceededException
     */
    public function checkProjectCreationLimit(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $maxProjects = (int) config('sakala.pilot_limits.max_projects_per_user', 3);

        // Lock user record if inside an active transaction to ensure atomicity
        $currentCount = Project::where('user_id', $user->id)->count();

        if ($currentCount >= $maxProjects) {
            throw new ProjectLimitExceededException($maxProjects, $currentCount);
        }
    }

    /**
     * Check whether a project deployment can be triggered under active deployment limits.
     *
     * @throws ActiveDeploymentLimitExceededException
     */
    public function checkActiveDeploymentLimit(User $user, Project $project): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $maxPerProject = (int) config('sakala.pilot_limits.max_active_deployments_per_project', 1);
        $projectActiveCount = Deployment::where('project_id', $project->id)
            ->active()
            ->count();

        if ($projectActiveCount >= $maxPerProject) {
            throw new ActiveDeploymentLimitExceededException(
                limit: $maxPerProject,
                current: $projectActiveCount,
                scope: 'project',
            );
        }

        $maxPerUser = (int) config('sakala.pilot_limits.max_active_deployments_per_user', 2);
        $userActiveCount = Deployment::whereHas('project', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->active()
            ->count();

        if ($userActiveCount >= $maxPerUser) {
            throw new ActiveDeploymentLimitExceededException(
                limit: $maxPerUser,
                current: $userActiveCount,
                scope: 'user',
            );
        }
    }

    /**
     * Get summary of quotas and current usage for the given user.
     */
    public function getPilotQuotaLimits(User $user): PilotQuotaLimitsData
    {
        $maxProjects = (int) config('sakala.pilot_limits.max_projects_per_user', 3);
        $maxActiveUser = (int) config('sakala.pilot_limits.max_active_deployments_per_user', 2);
        $maxActiveProject = (int) config('sakala.pilot_limits.max_active_deployments_per_project', 1);

        $currentProjects = Project::where('user_id', $user->id)->count();
        $currentActiveDeployments = Deployment::whereHas('project', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->active()
            ->count();

        return new PilotQuotaLimitsData(
            max_projects_per_user: $maxProjects,
            max_active_deployments_per_user: $maxActiveUser,
            max_active_deployments_per_project: $maxActiveProject,
            current_projects_count: $currentProjects,
            current_active_deployments_count: $currentActiveDeployments,
        );
    }
}
