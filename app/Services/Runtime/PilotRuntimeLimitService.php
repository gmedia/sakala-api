<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Data\Runtime\EffectiveRuntimeLimitsData;
use App\Data\Runtime\LogBoundsData;
use App\Data\Runtime\PilotQuotaLimitsData;
use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Data\Runtime\RuntimeTimeoutLimitsData;
use App\Data\Runtime\ScheduleLimitsData;
use App\Data\Runtime\WorkspaceLimitsData;
use App\Enums\DeploymentBuildMode;
use App\Enums\WorkspaceType;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\BuildRateLimitExceededException;
use App\Exceptions\Runtime\ProjectLimitExceededException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
use App\Exceptions\Runtime\ResourceLimitExceededException;
use App\Exceptions\Runtime\WorkerContainerLimitExceededException;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\ManagedBucket;
use App\Models\ManagedDatabase;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class PilotRuntimeLimitService
{
    public const TEAM_DEFAULT_MAX_PROJECTS = 2;

    public const TEAM_DEFAULT_MAX_ACTIVE_CONTAINERS = 2;

    public const TEAM_DEFAULT_MAX_ACTIVE_DEPLOYMENTS = 1;

    /** Builds per hour of a workspace that follows the global setting, when that is unset or empty. */
    public const DEFAULT_MAX_BUILDS_PER_HOUR = 20;

    /** Managed databases of a workspace that follows the global setting, when that is unset or empty (ADR-020). */
    public const DEFAULT_MAX_DATABASES = 2;

    /** Managed database storage in MB of a workspace that follows the global setting, when that is unset or empty. */
    public const DEFAULT_MAX_DATABASE_STORAGE_MB = 512;

    /**
     * Managed buckets of a workspace that follows the global setting, when
     * that is unset or empty (ADR-021). 0: buckets commit real disk, so
     * workspaces are opened one by one through limit profiles.
     */
    public const DEFAULT_MAX_BUCKETS = 0;

    /** MB the bucket quotas of such a workspace may reserve together; 0 for the same reason. */
    public const DEFAULT_MAX_BUCKET_STORAGE_MB = 0;

    /**
     * Managed Valkey stores of a workspace that follows the global setting,
     * when that is unset or empty (ADR-023). 0: a store is memory the node
     * keeps for good, so workspaces are opened one by one through limit
     * profiles.
     */
    public const DEFAULT_MAX_VALKEYS = 0;

    /** MB of `maxmemory` the stores of such a workspace may reserve together; 0 for the same reason. */
    public const DEFAULT_MAX_VALKEY_MEMORY_MB = 0;

    /**
     * Worker containers of a workspace that follows the global setting, when
     * that is unset or empty (ADR-022, option A): one, so a Laravel queue
     * worker runs from an ordinary account. Workers also count against
     * max_active_containers, so they never add capacity.
     */
    public const DEFAULT_MAX_WORKER_CONTAINERS = 1;

    /** Enabled schedules of such a workspace (option A). */
    public const DEFAULT_MAX_SCHEDULES = 2;

    /** Shortest gap between two slots of a schedule of such a workspace, in minutes (option A). */
    public const DEFAULT_MIN_SCHEDULE_INTERVAL_MINUTES = 15;

    /** Scheduled runs such a workspace runs at once (option A). */
    public const DEFAULT_MAX_CONCURRENT_JOB_RUNS = 1;

    /** The rolling window the build rate limit counts deployments in: one hour. */
    public const BUILD_RATE_WINDOW_SECONDS = 3600;

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
            return WorkspaceLimitsData::fromProfile(
                $profile,
                $this->idleSleepMinutesFor($profile),
                $this->maxReplicasFor($profile),
                $this->maxBuildsPerHourFor($profile),
                $this->maxDatabasesFor($profile),
                $this->maxDatabaseStorageMbFor($profile),
                $this->maxBucketsFor($profile),
                $this->maxBucketStorageMbFor($profile),
                $this->scheduleLimitsFor($profile),
                $this->maxValkeysFor($profile),
                $this->maxValkeyMemoryMbFor($profile),
            );
        }

        $maxProjects = (int) config('sakala.pilot_limits.max_projects_per_user', 3);
        $workloads = $this->scheduleLimitsFor(null);
        $personal = new WorkspaceLimitsData(
            max_projects: $maxProjects,
            max_active_containers: self::configuredOr('sakala.pilot_limits.max_active_containers_per_workspace', $maxProjects),
            max_active_deployments: (int) config('sakala.pilot_limits.max_active_deployments_per_user', 2),
            max_memory_mb: (int) config('sakala.pilot_limits.resources.max_memory_mb', 512),
            max_cpu_millis: (int) config('sakala.pilot_limits.resources.max_cpu_millis', 1000),
            max_builds_per_hour: $this->maxBuildsPerHourFor(null),
            idle_sleep_minutes: $this->idleSleepMinutesFor(null),
            max_replicas: $this->maxReplicasFor(null),
            max_databases: $this->maxDatabasesFor(null),
            max_database_storage_mb: $this->maxDatabaseStorageMbFor(null),
            max_buckets: $this->maxBucketsFor(null),
            max_bucket_storage_mb: $this->maxBucketStorageMbFor(null),
            max_worker_containers: $workloads->maxWorkerContainers,
            max_schedules: $workloads->maxSchedules,
            min_schedule_interval_minutes: $workloads->minScheduleIntervalMinutes,
            max_concurrent_job_runs: $workloads->maxConcurrentJobRuns,
            max_valkeys: $this->maxValkeysFor(null),
            max_valkey_memory_mb: $this->maxValkeyMemoryMbFor(null),
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
            max_builds_per_hour: $personal->max_builds_per_hour,
            idle_sleep_minutes: $personal->idle_sleep_minutes,
            max_replicas: $personal->max_replicas,
            max_databases: $personal->max_databases,
            max_database_storage_mb: $personal->max_database_storage_mb,
            max_buckets: $personal->max_buckets,
            max_bucket_storage_mb: $personal->max_bucket_storage_mb,
            max_worker_containers: $personal->max_worker_containers,
            max_schedules: $personal->max_schedules,
            min_schedule_interval_minutes: $personal->min_schedule_interval_minutes,
            max_concurrent_job_runs: $personal->max_concurrent_job_runs,
            max_valkeys: $personal->max_valkeys,
            max_valkey_memory_mb: $personal->max_valkey_memory_mb,
        );
    }

    /**
     * The worker and schedule limits of a workspace under this profile
     * (ADR-022). A profile without its own value, and a workspace without a
     * profile (personal or team), follow `sakala.workers.default_max_containers`
     * and the `sakala.schedules.default_*` settings: unset or empty means the
     * DEFAULT_ constants above (option A). 0 allows no worker container, no
     * schedule, or no run; the minimum interval is at least one minute. This
     * is the only place that decides.
     */
    public function scheduleLimitsFor(?LimitProfile $profile): ScheduleLimitsData
    {
        return new ScheduleLimitsData(
            maxWorkerContainers: max(0, $profile->max_worker_containers
                ?? self::configuredOr('sakala.workers.default_max_containers', self::DEFAULT_MAX_WORKER_CONTAINERS)),
            maxSchedules: max(0, $profile->max_schedules
                ?? self::configuredOr('sakala.schedules.default_max_count', self::DEFAULT_MAX_SCHEDULES)),
            minScheduleIntervalMinutes: max(LimitProfile::MIN_SCHEDULE_INTERVAL_FLOOR, $profile->min_schedule_interval_minutes
                ?? self::configuredOr('sakala.schedules.default_min_interval_minutes', self::DEFAULT_MIN_SCHEDULE_INTERVAL_MINUTES)),
            maxConcurrentJobRuns: max(0, $profile->max_concurrent_job_runs
                ?? self::configuredOr('sakala.schedules.default_max_concurrent_runs', self::DEFAULT_MAX_CONCURRENT_JOB_RUNS)),
        );
    }

    /**
     * Managed databases a workspace under this profile may hold. A profile
     * without its own value, and a workspace without a profile (personal or
     * team), follow `sakala.databases.default_max_count`: unset or empty means
     * DEFAULT_MAX_DATABASES. 0 allows none. This is the only place that decides.
     */
    public function maxDatabasesFor(?LimitProfile $profile): int
    {
        return max(0, $profile->max_databases
            ?? self::configuredOr('sakala.databases.default_max_count', self::DEFAULT_MAX_DATABASES));
    }

    /**
     * Total size in MB of the managed databases of a workspace under this
     * profile. A profile without its own value, and a workspace without a
     * profile, follow `sakala.databases.default_max_storage_mb`: unset or empty
     * means DEFAULT_MAX_DATABASE_STORAGE_MB. 0 allows none. This is the only
     * place that decides.
     */
    public function maxDatabaseStorageMbFor(?LimitProfile $profile): int
    {
        return max(0, $profile->max_database_storage_mb
            ?? self::configuredOr('sakala.databases.default_max_storage_mb', self::DEFAULT_MAX_DATABASE_STORAGE_MB));
    }

    /**
     * Managed buckets a workspace under this profile may hold. A profile
     * without its own value, and a workspace without a profile, follow
     * `sakala.buckets.default_max_count`: unset or empty means
     * DEFAULT_MAX_BUCKETS. 0 allows none. This is the only place that decides.
     */
    public function maxBucketsFor(?LimitProfile $profile): int
    {
        return max(0, $profile->max_buckets
            ?? self::configuredOr('sakala.buckets.default_max_count', self::DEFAULT_MAX_BUCKETS));
    }

    /**
     * MB the bucket quotas of a workspace under this profile may reserve
     * together. A profile without its own value, and a workspace without a
     * profile, follow `sakala.buckets.default_max_storage_mb`: unset or empty
     * means DEFAULT_MAX_BUCKET_STORAGE_MB. 0 allows no new allocation;
     * existing buckets keep their quotas. This is the only place that decides.
     */
    public function maxBucketStorageMbFor(?LimitProfile $profile): int
    {
        return max(0, $profile->max_bucket_storage_mb
            ?? self::configuredOr('sakala.buckets.default_max_storage_mb', self::DEFAULT_MAX_BUCKET_STORAGE_MB));
    }

    /**
     * Managed Valkey stores a workspace under this profile may hold. A
     * profile without its own value, and a workspace without a profile,
     * follow `sakala.valkey.default_max_count`: unset or empty means
     * DEFAULT_MAX_VALKEYS. 0 allows none. This is the only place that decides.
     */
    public function maxValkeysFor(?LimitProfile $profile): int
    {
        return max(0, $profile->max_valkeys
            ?? self::configuredOr('sakala.valkey.default_max_count', self::DEFAULT_MAX_VALKEYS));
    }

    /**
     * MB of `maxmemory` the Valkey stores of a workspace under this profile
     * may reserve together. A profile without its own value, and a workspace
     * without a profile, follow `sakala.valkey.default_max_memory_mb`: unset
     * or empty means DEFAULT_MAX_VALKEY_MEMORY_MB. 0 allows no new store and
     * no growth; existing stores keep their size. This is the only place that
     * decides.
     */
    public function maxValkeyMemoryMbFor(?LimitProfile $profile): int
    {
        return max(0, $profile->max_valkey_memory_mb
            ?? self::configuredOr('sakala.valkey.default_max_memory_mb', self::DEFAULT_MAX_VALKEY_MEMORY_MB));
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
     * Deployments that build an image a workspace under this profile may
     * create in the rolling hour; null means no limit. One that reuses the
     * live image does not count (checkBuildRateLimit()). A profile without
     * its own value, and a workspace without a profile (personal or team),
     * follow `sakala.project.max_builds_per_hour`: unset or empty means
     * DEFAULT_MAX_BUILDS_PER_HOUR, and 0 turns the limit off. This is the
     * only place that decides.
     */
    public function maxBuildsPerHourFor(?LimitProfile $profile): ?int
    {
        $limit = $profile->max_builds_per_hour
            ?? self::configuredOr('sakala.project.max_builds_per_hour', self::DEFAULT_MAX_BUILDS_PER_HOUR);

        return $limit > 0 ? $limit : null;
    }

    /**
     * Most replicas a project of a workspace under this profile may run. A
     * profile without its own value, and a workspace without a profile, allow
     * one, so replicas stay off until an admin allows more. This is the only
     * place that decides.
     */
    public function maxReplicasFor(?LimitProfile $profile): int
    {
        return max(Project::DEFAULT_REPLICAS, $profile->max_replicas ?? Project::DEFAULT_REPLICAS);
    }

    /** maxReplicasFor() the workspace's profile; one for a project without a workspace. */
    public function maxReplicasIn(?Workspace $workspace): int
    {
        $profile = $workspace?->limit_profile_id === null ? null : $workspace->limitProfile;

        return $this->maxReplicasFor($profile);
    }

    /**
     * Replicas the project's next deployment runs: its setting, within what its
     * workspace allows now. A profile whose maximum was lowered applies from the
     * next deployment, without touching the project's setting.
     */
    public function replicasFor(Project $project): int
    {
        return max(Project::DEFAULT_REPLICAS, min($project->replicas, $this->maxReplicasIn($project->workspace)));
    }

    /**
     * The region the project must run in: its own `region`, else its
     * workspace profile's `default_region`, else null for any region. Only
     * placement of a project without a workload reads it
     * (AgentNodeSchedulerService). This is the only place that decides.
     */
    public function regionFor(Project $project): ?string
    {
        if ($project->region !== null) {
            return $project->region;
        }

        return $this->defaultRegionOf($project->workspace);
    }

    /**
     * The workspace profile's `default_region`, or null for any region: where
     * the workspace's projects run unless they set their own, and where its
     * managed databases are created unless the request names a region.
     */
    public function defaultRegionOf(?Workspace $workspace): ?string
    {
        $profile = $workspace?->limit_profile_id === null ? null : $workspace->limitProfile;

        return $profile?->default_region;
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
     * `$workerContainers` are the worker containers the new deployment runs
     * next to its replicas (ADR-022); they count against
     * `max_active_containers` like replicas, and against
     * `max_worker_containers` on their own.
     *
     * @throws ActiveDeploymentLimitExceededException
     * @throws ActiveContainerLimitExceededException
     * @throws WorkerContainerLimitExceededException
     */
    public function checkActiveDeploymentLimit(User $actor, Project $project, int $workerContainers = 0): void
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

        // Redeploying a project that already serves replaces its containers, so
        // only containers beyond the ones it runs need a free slot: going from 1
        // to 3 needs 2. A sleeping one holds no slot, so its redeploy takes
        // all of its containers like a wake. Worker containers count like
        // replicas (ADR-022).
        $serving = $project->isServing() ? $project->activeDeployment : null;
        $heldWorkers = $serving === null ? 0 : $serving->worker_containers;

        if ($workerContainers > $heldWorkers) {
            $otherWorkers = $this->workerContainersIn($workspace, except: $project);

            if ($otherWorkers + $workerContainers > $limits->max_worker_containers) {
                throw new WorkerContainerLimitExceededException($limits->max_worker_containers, $otherWorkers, $workerContainers);
            }
        }

        $required = $this->replicasFor($project) + $workerContainers;
        $held = $serving === null ? 0 : $serving->replicas + $serving->worker_containers;

        if ($required <= $held) {
            return;
        }

        $others = $this->activeContainersIn($workspace, except: $project);

        if ($others + $required > $limits->max_active_containers) {
            throw new ActiveContainerLimitExceededException($limits->max_active_containers, $others + $held, $required, $workerContainers);
        }
    }

    /**
     * Whether the workspace may start another build. Every deployment that
     * builds an image counts: those of the workspace's projects created in the
     * rolling window, whatever their status, including those of deleted
     * projects so deleting and recreating does not reset the window. A
     * deployment that reuses the live image builds nothing, so the caller does
     * not check it and it does not count; one whose reuse fell back to a build
     * counts from then on (it is `built`), without being checked again.
     * Admins acting are not limited, like checkActiveDeploymentLimit(). The
     * caller holds the workspace row lock inside a transaction, so two
     * deployments cannot both read the count below the limit.
     *
     * @throws BuildRateLimitExceededException
     */
    public function checkBuildRateLimit(User $actor, Project $project): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $workspace = $project->workspace;

        if ($workspace === null) {
            return;
        }

        $limit = $this->limitsFor($workspace)->max_builds_per_hour;

        if ($limit === null) {
            return;
        }

        $recent = $this->buildsInWindow($workspace);
        $current = $recent->count();

        if ($current < $limit) {
            return;
        }

        // Ordered oldest first, a slot frees when the build at index
        // current - limit leaves the window. That is the oldest one unless an
        // admin lowered the limit below what already ran.
        $freeing = $recent->orderBy('created_at')->offset($current - $limit)->first(['created_at']);
        $freesAt = $freeing?->created_at?->addSeconds(self::BUILD_RATE_WINDOW_SECONDS) ?? now();

        throw new BuildRateLimitExceededException(
            limit: $limit,
            current: $current,
            retryAfterSeconds: max(1, (int) ceil(now()->diffInSeconds($freesAt))),
        );
    }

    /**
     * Whether waking this sleeping project stays within its workspace's running
     * container limit. A wake starts every replica and worker container of the
     * deployment the node keeps (`$workload`), whatever the project's setting
     * says now; its worker containers also stay within `max_worker_containers`. Applies to
     * everyone, admins included: a wake can come from an anonymous visitor, and
     * the limit is what keeps a node's slots shared. The caller holds the
     * project row lock.
     *
     * @throws ProjectWakeLimitExceededException
     */
    public function checkWakeLimit(Project $project, Deployment $workload, bool $exposeUsage = true): void
    {
        $workspace = $project->workspace;

        if ($workspace === null) {
            return;
        }

        $limits = $this->limitsFor($workspace);
        $limit = $limits->max_active_containers;
        $containers = $this->activeContainersIn($workspace, except: $project);
        $requested = $workload->replicas + $workload->worker_containers;

        if ($containers + $requested > $limit) {
            throw new ProjectWakeLimitExceededException($limit, $containers, $exposeUsage, $requested);
        }

        if ($workload->worker_containers > 0) {
            $workers = $this->workerContainersIn($workspace, except: $project);

            if ($workers + $workload->worker_containers > $limits->max_worker_containers) {
                throw new ProjectWakeLimitExceededException($limits->max_worker_containers, $workers, $exposeUsage, $workload->worker_containers, workers: true);
            }
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
            current_databases_count: ManagedDatabase::countIn($workspace->id),
            current_database_storage_bytes: ManagedDatabase::storageBytesIn($workspace->id),
            current_buckets_count: ManagedBucket::countIn($workspace->id),
            current_bucket_storage_mb: ManagedBucket::reservedMbIn($workspace->id),
            current_bucket_usage_bytes: ManagedBucket::usageBytesIn($workspace->id),
            current_worker_containers_count: $this->workerContainersIn($workspace),
            current_schedules_count: ProjectSchedule::enabledCountIn($workspace->id),
            current_valkeys_count: ManagedValkey::countIn($workspace->id),
            current_valkey_memory_mb: ManagedValkey::reservedMbIn($workspace->id),
        );
    }

    /** An unset or empty variable (`KEY=` in .env) means "use the fallback", never 0. */
    private static function configuredOr(string $key, int $fallback): int
    {
        $value = config($key);

        return filled($value) ? (int) $value : $fallback;
    }

    /**
     * Deployments of the workspace's projects, deleted ones included, created
     * within BUILD_RATE_WINDOW_SECONDS that build an image (not `reused`).
     * Served by `deployments (project_id, created_at)`; `build_mode` is read
     * from those rows.
     *
     * @return Builder<Deployment>
     */
    private function buildsInWindow(Workspace $workspace): Builder
    {
        return Deployment::query()
            ->whereIn('project_id', Project::withTrashed()->select('id')->where('workspace_id', $workspace->id))
            ->where('created_at', '>', now()->subSeconds(self::BUILD_RATE_WINDOW_SECONDS))
            ->where('build_mode', '!=', DeploymentBuildMode::Reused->value);
    }

    private function activeDeploymentsIn(Workspace $workspace): int
    {
        return Deployment::query()
            ->whereIn('project_id', Project::query()->select('id')->where('workspace_id', $workspace->id))
            ->active()
            ->count();
    }

    /**
     * Running worker containers of the workspace (Project::heldWorkerContainers()),
     * for `max_worker_containers`. One query.
     */
    public function workerContainersIn(Workspace $workspace, ?Project $except = null): int
    {
        return Project::query()
            ->where('workspace_id', $workspace->id)
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except?->id))
            ->holdingContainers()
            ->get()
            ->sum(static fn (Project $project): int => $project->heldWorkerContainers());
    }

    /**
     * Running containers of the workspace: the replicas and worker containers
     * of every project that serves, or is being deployed and will serve
     * (Project::heldContainers()).
     * A sleeping project keeps its active deployment but runs nothing, so it
     * does not count. One query.
     */
    private function activeContainersIn(Workspace $workspace, ?Project $except = null): int
    {
        return Project::query()
            ->where('workspace_id', $workspace->id)
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except?->id))
            ->holdingContainers()
            ->get()
            ->sum(static fn (Project $project): int => $project->heldContainers());
    }
}
