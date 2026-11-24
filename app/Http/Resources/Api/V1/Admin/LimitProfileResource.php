<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\LimitProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LimitProfile
 *
 * @property LimitProfile $resource
 */
final class LimitProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;

        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'max_projects' => $profile->max_projects,
            /** Projects that serve or are being deployed at once. */
            'max_active_containers' => $profile->max_active_containers,
            'max_active_deployments' => $profile->max_active_deployments,
            /** Per project. */
            'max_memory_mb' => $profile->max_memory_mb,
            /** Per project. */
            'max_cpu_millis' => $profile->max_cpu_millis,
            /**
             * Deployments the workspace may create per rolling hour; every deployment builds. `null` follows the
             * global `SAKALA_PROJECT_MAX_BUILDS_PER_HOUR`.
             */
            'max_builds_per_hour' => $profile->max_builds_per_hour,
            /**
             * Minutes without a request before a project sleeps. `0` never sleeps; `null` follows the global
             * `SAKALA_PROJECT_IDLE_SLEEP_MINUTES`.
             */
            'idle_sleep_minutes' => $profile->idle_sleep_minutes,
            /**
             * Most replicas per project. `null` allows one, so replicas stay off.
             */
            'max_replicas' => $profile->max_replicas,
            /** Region the workspace's projects run in unless a project sets its own; `null` for any region. */
            'default_region' => $profile->default_region,
            /**
             * Managed databases the workspace may hold. `null` follows the global `SAKALA_DATABASE_DEFAULT_MAX_COUNT`;
             * `0` allows none.
             */
            'max_databases' => $profile->max_databases,
            /**
             * Total size of the workspace's managed databases, in MB. `null` follows the global
             * `SAKALA_DATABASE_DEFAULT_MAX_STORAGE_MB`; `0` allows none.
             */
            'max_database_storage_mb' => $profile->max_database_storage_mb,
            /**
             * Managed buckets the workspace may hold. `null` follows the global `SAKALA_BUCKET_DEFAULT_MAX_COUNT`;
             * `0` allows none.
             */
            'max_buckets' => $profile->max_buckets,
            /**
             * MB the quotas of the workspace's buckets may reserve together. `null` follows the global
             * `SAKALA_BUCKET_DEFAULT_MAX_STORAGE_MB`; `0` refuses new buckets and quota increases.
             */
            'max_bucket_storage_mb' => $profile->max_bucket_storage_mb,
            /**
             * Worker containers the workspace may run at once. `null` follows the global
             * `SAKALA_WORKER_DEFAULT_MAX_CONTAINERS`; `0` allows none.
             */
            'max_worker_containers' => $profile->max_worker_containers,
            /** Enabled schedules the workspace may hold. `null` follows the global `SAKALA_SCHEDULE_DEFAULT_MAX_COUNT`; `0` allows none. */
            'max_schedules' => $profile->max_schedules,
            /** Shortest gap between two slots of a schedule, in minutes. `null` follows the global `SAKALA_SCHEDULE_DEFAULT_MIN_INTERVAL_MINUTES`. */
            'min_schedule_interval_minutes' => $profile->min_schedule_interval_minutes,
            /** Scheduled runs the workspace runs at once. `null` follows the global `SAKALA_SCHEDULE_DEFAULT_MAX_CONCURRENT_RUNS`; `0` runs none. */
            'max_concurrent_job_runs' => $profile->max_concurrent_job_runs,
            /**
             * Workspaces running under this profile. A profile is deleted only at zero.
             *
             * @var int|null
             */
            'workspaces_count' => $this->whenCounted('workspaces'),
            'created_at' => $profile->created_at?->toAtomString(),
            'updated_at' => $profile->updated_at?->toAtomString(),
        ];
    }
}
