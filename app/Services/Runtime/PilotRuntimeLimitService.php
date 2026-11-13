<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Data\Runtime\EffectiveRuntimeLimitsData;
use App\Data\Runtime\LogBoundsData;
use App\Data\Runtime\PilotQuotaLimitsData;
use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Data\Runtime\RuntimeTimeoutLimitsData;
use App\Data\Runtime\WorkspaceLimitsData;
use App\Enums\RuntimeStatus;
use App\Enums\WorkspaceType;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\ProjectLimitExceededException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Exceptions\Runtime\ResourceLimitExceededException;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class PilotRuntimeLimitService
{
    public const TEAM_DEFAULT_MAX_PROJECTS = 2;

    public const TEAM_DEFAULT_MAX_ACTIVE_CONTAINERS = 2;

    public const TEAM_DEFAULT_MAX_ACTIVE_DEPLOYMENTS = 1;

    /**
     * The workspace's limit profile, or the default for its type. A personal
     * workspace without a profile runs under exactly the pilot limits every
     * account had before workspaces. A team without one gets the small team
     * defaults (2 projects, 2 running containers, 1 deployment at a time), never
     * the personal values, so creating teams does not multiply quota.
     */
    public function limitsFor(Workspace $workspace): WorkspaceLimitsData
    {
        $profile = $workspace->limit_profile_id === null ? null : $workspace->limitProfile;

        if ($profile !== null) {
            return WorkspaceLimitsData::fromProfile($profile, $this->idleSleepMinutesFor($profile));
        }

        $maxProjects = (int) config('sakala.pilot_limits.max_projects_per_user', 3);
        $personal = new WorkspaceLimitsData(
            max_projects: $maxProjects,
            max_active_containers: self::configuredOr('sakala.pilot_limits.max_active_containers_per_workspace', $maxProjects),
            max_active_deployments: (int) config('sakala.pilot_limits.max_active_deployments_per_user', 2),
            max_memory_mb: (int) config('sakala.pilot_limits.resources.max_memory_mb', 512),
            max_cpu_millis: (int) config('sakala.pilot_limits.resources.max_cpu_millis', 1000),
            max_builds_per_hour: null,
            idle_sleep_minutes: $this->idleSleepMinutesFor(null),
        );

        if ($workspace->type === WorkspaceType::Personal) {
            return $personal;
        }

        $team = static fn (string $key, int $fallback): int => self::configuredOr("sakala.workspaces.team_limits.{$key}", $fallback);

        return new WorkspaceLimitsData(
            max_projects: $team('max_projects', self::TEAM_DEFAULT_MAX_PROJECTS),
            max_active_containers: $team('max_active_containers', self::TEAM_DEFAULT_MAX_ACTIVE_CONTAINERS),
            max_active_deployments: $team('max_active_deployments', self::TEAM_DEFAULT_MAX_ACTIVE_DEPLOYMENTS),
            max_memory_mb: $team('max_memory_mb', $personal->max_memory_mb),
            max_cpu_millis: $team('max_cpu_millis', $personal->max_cpu_millis),
            max_builds_per_hour: null,
            idle_sleep_minutes: $personal->idle_sleep_minutes,
        );
    }

    /**
     * Minutes without a request before a project of a workspace under this
     * profile is put to sleep; 0 means never. A profile without its own value,
     * and a workspace without a profile (personal or team), follow
     * `sakala.project.idle_sleep_minutes`. This is the only place that decides.
     */
    public function idleSleepMinutesFor(?LimitProfile $profile): int
    {
        if ($profile?->idle_sleep_minutes !== null) {
            return max(0, $profile->idle_sleep_minutes);
        }

        return max(0, (int) config('sakala.project.idle_sleep_minutes', 0));
    }

    /**
     * Resolve effective runtime limits from the requested resources and the
     * caps of the workspace that owns the project. The default never exceeds
     * the cap, so a profile with a small cap still deploys without a request.
     *
     * @throws ResourceLimitExceededException
     */
    public function resolveEffectiveLimits(
        ?RuntimeResourceLimitsData $requested = null,
        ?Workspace $workspace = null,
    ): EffectiveRuntimeLimitsData {
        $limits = $workspace === null ? null : $this->limitsFor($workspace);

        $maxMemory = $limits->max_memory_mb ?? (int) config('sakala.pilot_limits.resources.max_memory_mb', 512);
        $defaultMemory = min((int) config('sakala.pilot_limits.resources.default_memory_mb', 256), $maxMemory);

        $maxCpu = $limits->max_cpu_millis ?? (int) config('sakala.pilot_limits.resources.max_cpu_millis', 1000);
        $defaultCpu = min((int) config('sakala.pilot_limits.resources.default_cpu_millis', 500), $maxCpu);

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
     * Whether the workspace may hold another project. Admins acting anywhere
     * are not limited, as before workspaces.
     *
     * The caller holds the workspace row lock inside a transaction, so two
     * creations cannot both read the count below the limit.
     *
     * @throws ProjectLimitExceededException
     */
    public function checkProjectCreationLimit(User $actor, Workspace $workspace): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $maxProjects = $this->limitsFor($workspace)->max_projects;
        $currentCount = Project::query()->where('workspace_id', $workspace->id)->count();

        if ($currentCount >= $maxProjects) {
            throw new ProjectLimitExceededException(
                limit: $maxProjects,
                current: $currentCount,
                workspaceId: $workspace->id,
                team: ! $workspace->isPersonal(),
            );
        }
    }

    /**
     * Check whether a project deployment can be triggered under the workspace's
     * limits. The caller holds the workspace and project row locks inside a
     * transaction.
     *
     * @throws ActiveDeploymentLimitExceededException
     * @throws ActiveContainerLimitExceededException
     */
    public function checkActiveDeploymentLimit(User $actor, Project $project): void
    {
        if ($actor->isAdmin()) {
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

        $workspace = $project->workspace;

        if ($workspace === null) {
            return;
        }

        $limits = $this->limitsFor($workspace);
        $workspaceActiveCount = $this->activeDeploymentsIn($workspace);

        if ($workspaceActiveCount >= $limits->max_active_deployments) {
            throw new ActiveDeploymentLimitExceededException(
                limit: $limits->max_active_deployments,
                current: $workspaceActiveCount,
                // `user` is what the console already knows for a personal workspace.
                scope: $workspace->isPersonal() ? 'user' : 'workspace',
            );
        }

        // Redeploying a project that already serves replaces its container. A
        // sleeping one holds no slot, so its redeploy takes one like a wake.
        if ($project->active_deployment_id !== null && $project->runtime_status !== RuntimeStatus::Sleeping) {
            return;
        }

        $containers = $this->activeContainersIn($workspace, except: $project);

        if ($containers >= $limits->max_active_containers) {
            throw new ActiveContainerLimitExceededException($limits->max_active_containers, $containers);
        }
    }

    /**
     * Whether waking this sleeping project stays within its workspace's running
     * container limit. Applies to everyone, admins included: a wake can come
     * from an anonymous visitor, and the limit is what keeps a node's slots
     * shared. The caller holds the project row lock.
     *
     * @throws ProjectWakeLimitExceededException
     */
    public function checkWakeLimit(Project $project, bool $exposeUsage = true): void
    {
        $workspace = $project->workspace;

        if ($workspace === null) {
            return;
        }

        $limit = $this->limitsFor($workspace)->max_active_containers;
        $containers = $this->activeContainersIn($workspace, except: $project);

        if ($containers >= $limit) {
            throw new ProjectWakeLimitExceededException($limit, $containers, $exposeUsage);
        }
    }

    /**
     * Quotas and current usage of one workspace, for the console.
     */
    public function getPilotQuotaLimits(Workspace $workspace): PilotQuotaLimitsData
    {
        $limits = $this->limitsFor($workspace);

        return new PilotQuotaLimitsData(
            workspace_id: $workspace->id,
            max_projects_per_user: $limits->max_projects,
            max_active_deployments_per_user: $limits->max_active_deployments,
            max_active_deployments_per_project: (int) config('sakala.pilot_limits.max_active_deployments_per_project', 1),
            current_projects_count: Project::query()->where('workspace_id', $workspace->id)->count(),
            current_active_deployments_count: $this->activeDeploymentsIn($workspace),
            max_active_containers: $limits->max_active_containers,
            current_active_containers_count: $this->activeContainersIn($workspace),
            limits: $limits,
        );
    }

    /** An unset or empty variable (`KEY=` in .env) means "use the fallback", never 0. */
    private static function configuredOr(string $key, int $fallback): int
    {
        $value = config($key);

        return filled($value) ? (int) $value : $fallback;
    }

    private function activeDeploymentsIn(Workspace $workspace): int
    {
        return Deployment::query()
            ->whereIn('project_id', Project::query()->select('id')->where('workspace_id', $workspace->id))
            ->active()
            ->count();
    }

    /**
     * Projects that serve, or are being deployed and will serve, so two
     * deployments racing for the last slot cannot both pass. A sleeping project
     * keeps its active deployment but runs nothing, so it does not count.
     */
    private function activeContainersIn(Workspace $workspace, ?Project $except = null): int
    {
        return Project::query()
            ->where('workspace_id', $workspace->id)
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except?->id))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $serving) => $serving
                    ->whereNotNull('active_deployment_id')
                    ->where('runtime_status', '!=', RuntimeStatus::Sleeping))
                ->orWhereHas('deployments', fn (Builder $deployments) => $deployments->active()))
            ->count();
    }
}
