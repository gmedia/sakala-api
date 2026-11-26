<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LimitProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable resource limits attached to workspaces. Limits count per workspace,
 * never per member.
 *
 * @property string $id
 * @property string $name
 * @property int $max_projects
 * @property int $max_active_containers
 * @property int $max_active_deployments
 * @property int $max_memory_mb
 * @property int $max_cpu_millis
 * @property int|null $max_builds_per_hour Deployments that build an image the workspace may create per rolling hour; one that reuses the live image does not count. Null follows the global setting (PilotRuntimeLimitService::maxBuildsPerHourFor()).
 * @property int|null $idle_sleep_minutes Null follows the global setting, 0 never sleeps, N sleeps after N idle minutes.
 * @property int|null $max_replicas Most replicas per project. Null allows one (Project::DEFAULT_REPLICAS), so replicas stay off unless an admin allows more.
 * @property string|null $default_region Region the workspace's projects run in unless a project sets its own; null for any region.
 * @property int|null $max_databases Managed databases the workspace may hold. Null follows `SAKALA_DATABASE_DEFAULT_MAX_COUNT`; 0 allows none.
 * @property int|null $max_database_storage_mb Total size of the workspace's managed databases, in MB. Null follows `SAKALA_DATABASE_DEFAULT_MAX_STORAGE_MB`; 0 allows none.
 * @property int|null $max_buckets Managed buckets the workspace may hold. Null follows `SAKALA_BUCKET_DEFAULT_MAX_COUNT`; 0 allows none.
 * @property int|null $max_bucket_storage_mb Sum of the workspace's bucket quotas, in MB. Null follows `SAKALA_BUCKET_DEFAULT_MAX_STORAGE_MB`; 0 allows no new allocation.
 * @property int|null $max_valkeys Managed Valkey stores the workspace may hold. Null follows `SAKALA_VALKEY_DEFAULT_MAX_COUNT`; 0 allows none.
 * @property int|null $max_valkey_memory_mb Sum of the workspace's store sizes (`maxmemory`), in MB. Null follows `SAKALA_VALKEY_DEFAULT_MAX_MEMORY_MB`; 0 allows no new allocation.
 * @property int|null $max_custom_domains Custom domains the workspace's projects may hold together (ADR-024). Null follows `SAKALA_CUSTOM_DOMAIN_DEFAULT_MAX_COUNT`; 0 allows none.
 * @property int|null $max_worker_containers Worker containers the workspace may run at once (ADR-022). Null follows `SAKALA_WORKER_DEFAULT_MAX_CONTAINERS`; 0 allows none.
 * @property int|null $max_schedules Enabled schedules the workspace may hold. Null follows `SAKALA_SCHEDULE_DEFAULT_MAX_COUNT`; 0 allows none.
 * @property int|null $min_schedule_interval_minutes Shortest gap between two slots of a schedule. Null follows `SAKALA_SCHEDULE_DEFAULT_MIN_INTERVAL_MINUTES`.
 * @property int|null $max_concurrent_job_runs Scheduled runs the workspace runs at once. Null follows `SAKALA_SCHEDULE_DEFAULT_MAX_CONCURRENT_RUNS`; 0 runs none.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'max_projects',
    'max_active_containers',
    'max_active_deployments',
    'max_memory_mb',
    'max_cpu_millis',
    'max_builds_per_hour',
    'idle_sleep_minutes',
    'max_replicas',
    'default_region',
    'max_databases',
    'max_database_storage_mb',
    'max_buckets',
    'max_bucket_storage_mb',
    'max_valkeys',
    'max_valkey_memory_mb',
    'max_custom_domains',
    'max_worker_containers',
    'max_schedules',
    'min_schedule_interval_minutes',
    'max_concurrent_job_runs',
])]
class LimitProfile extends Model
{
    /** @use HasFactory<LimitProfileFactory> */
    use HasFactory, HasUuids;

    /** Longest idle window a profile may set: one week. */
    public const MAX_IDLE_SLEEP_MINUTES = 7 * 24 * 60;

    /**
     * Most replicas per project a profile may allow. All replicas of a project
     * run on one node, which caps them again on its own side
     * (`SAKALA_MAX_REPLICAS_PER_PROJECT` on the agent).
     */
    public const MAX_REPLICAS_CEILING = 10;

    /** Most managed databases a profile may allow per workspace. */
    public const MAX_DATABASES_CEILING = 1000;

    /** Most managed database storage a profile may allow per workspace: 1 TB, in MB. */
    public const MAX_DATABASE_STORAGE_MB_CEILING = 1024 * 1024;

    /** Most managed buckets a profile may allow per workspace. */
    public const MAX_BUCKETS_CEILING = 100;

    /** Most bucket storage a profile may allocate per workspace: 1 TiB, in MB. */
    public const MAX_BUCKET_STORAGE_MB_CEILING = 1024 * 1024;

    /** Most managed Valkey stores a profile may allow per workspace. */
    public const MAX_VALKEYS_CEILING = 20;

    /** Most Valkey memory a profile may allocate per workspace, in MB: 20 stores of the agent's largest size. */
    public const MAX_VALKEY_MEMORY_MB_CEILING = self::MAX_VALKEYS_CEILING * ManagedValkey::AGENT_MAX_MEMORY_MB;

    /** Most custom domains a profile may allow per workspace. */
    public const MAX_CUSTOM_DOMAINS_CEILING = 100;

    /** Most worker containers a profile may allow per workspace. */
    public const MAX_WORKER_CONTAINERS_CEILING = 100;

    /** Most enabled schedules a profile may allow per workspace. */
    public const MAX_SCHEDULES_CEILING = 1000;

    /** Shortest minimum interval a profile may set: every minute. */
    public const MIN_SCHEDULE_INTERVAL_FLOOR = 1;

    /** Longest minimum interval a profile may set: one day. */
    public const MIN_SCHEDULE_INTERVAL_CEILING = 24 * 60;

    /** Most concurrent scheduled runs a profile may allow per workspace. */
    public const MAX_CONCURRENT_JOB_RUNS_CEILING = 100;

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_projects' => 'integer',
            'max_active_containers' => 'integer',
            'max_active_deployments' => 'integer',
            'max_memory_mb' => 'integer',
            'max_cpu_millis' => 'integer',
            'max_builds_per_hour' => 'integer',
            'idle_sleep_minutes' => 'integer',
            'max_replicas' => 'integer',
            'max_databases' => 'integer',
            'max_database_storage_mb' => 'integer',
            'max_buckets' => 'integer',
            'max_bucket_storage_mb' => 'integer',
            'max_valkeys' => 'integer',
            'max_valkey_memory_mb' => 'integer',
            'max_custom_domains' => 'integer',
            'max_worker_containers' => 'integer',
            'max_schedules' => 'integer',
            'min_schedule_interval_minutes' => 'integer',
            'max_concurrent_job_runs' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
