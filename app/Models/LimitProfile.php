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
 * @property int|null $max_builds_per_hour Deployments the workspace may create per rolling hour. Null follows the global setting (PilotRuntimeLimitService::maxBuildsPerHourFor()).
 * @property int|null $idle_sleep_minutes Null follows the global setting, 0 never sleeps, N sleeps after N idle minutes.
 * @property int|null $max_replicas Most replicas per project. Null allows one (Project::DEFAULT_REPLICAS), so replicas stay off unless an admin allows more.
 * @property string|null $default_region Region the workspace's projects run in unless a project sets its own; null for any region.
 * @property int|null $max_databases Managed databases the workspace may hold. Null follows `SAKALA_DATABASE_DEFAULT_MAX_COUNT`; 0 allows none.
 * @property int|null $max_database_storage_mb Total size of the workspace's managed databases, in MB. Null follows `SAKALA_DATABASE_DEFAULT_MAX_STORAGE_MB`; 0 allows none.
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
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
