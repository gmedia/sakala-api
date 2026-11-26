<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Runtime\PilotQuotaLimitsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The limits a workspace runs under, from its limit profile or the default for
 * its type, with current usage.
 *
 * @property PilotQuotaLimitsData $resource
 */
final class WorkspaceQuotaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $limits = $this->resource->limits;

        return [
            /**
             * The attached profile, or null when the workspace uses the default of its type.
             */
            'limit_profile' => $limits->profile === null ? null : ['id' => $limits->profile->id, 'name' => $limits->profile->name],
            'limits' => [
                'max_projects' => $limits->max_projects,
                'max_active_containers' => $limits->max_active_containers,
                'max_active_deployments' => $limits->max_active_deployments,
                /** Per project. */
                'max_memory_mb' => $limits->max_memory_mb,
                /** Per project. */
                'max_cpu_millis' => $limits->max_cpu_millis,
                /**
                 * Effective deployments per rolling hour, from the profile or the global setting. `null` means no
                 * limit. Admins acting are not limited.
                 */
                'max_builds_per_hour' => $limits->max_builds_per_hour,
                /** Effective minutes without a request before a project sleeps, from the profile or the global setting. `0` never sleeps. */
                'idle_sleep_minutes' => $limits->idle_sleep_minutes,
                /** Effective most replicas per project, from the profile; 1 without one. */
                'max_replicas' => $limits->max_replicas,
                /** Effective managed databases, from the profile or the global setting. `0` allows none. */
                'max_databases' => $limits->max_databases,
                /** Effective total managed database storage in MB, from the profile or the global setting. `0` allows none. */
                'max_database_storage_mb' => $limits->max_database_storage_mb,
                /** Effective managed buckets, from the profile or the global setting. `0` allows none. */
                'max_buckets' => $limits->max_buckets,
                /** Effective MB the bucket quotas may reserve together, from the profile or the global setting. `0` allows no new allocation. */
                'max_bucket_storage_mb' => $limits->max_bucket_storage_mb,
                /** Effective managed Valkey stores, from the profile or the global setting. `0` allows none. */
                'max_valkeys' => $limits->max_valkeys,
                /** Effective MB of `maxmemory` the Valkey stores may reserve together. `0` allows no new allocation. */
                'max_valkey_memory_mb' => $limits->max_valkey_memory_mb,
                /** Effective custom domains, from the profile or the global setting. `0` allows none. */
                'max_custom_domains' => $limits->max_custom_domains,
                /** Effective worker containers at once, from the profile or the global setting. `0` allows none. */
                'max_worker_containers' => $limits->max_worker_containers,
                /** Effective enabled schedules, from the profile or the global setting. `0` allows none. */
                'max_schedules' => $limits->max_schedules,
                /** Effective shortest gap between two slots of a schedule, in minutes. */
                'min_schedule_interval_minutes' => $limits->min_schedule_interval_minutes,
                /** Effective scheduled runs at once, from the profile or the global setting. `0` runs none. */
                'max_concurrent_job_runs' => $limits->max_concurrent_job_runs,
                /** Global, the same for every workspace. */
                'max_active_deployments_per_project' => $this->resource->max_active_deployments_per_project,
            ],
            'usage' => [
                'projects' => $this->resource->current_projects_count,
                /** Replicas of the projects that serve or are being deployed; sleeping projects do not count. */
                'active_containers' => $this->resource->current_active_containers_count,
                'active_deployments' => $this->resource->current_active_deployments_count,
                /** Managed databases that count towards `max_databases`. */
                'databases' => $this->resource->current_databases_count,
                /** Reported size of the workspace's managed databases, in bytes. */
                'database_storage_bytes' => $this->resource->current_database_storage_bytes,
                /** Managed buckets that count towards `max_buckets`. */
                'buckets' => $this->resource->current_buckets_count,
                /** MB the workspace's bucket quotas reserve (allocated, not used). */
                'bucket_storage_mb' => $this->resource->current_bucket_storage_mb,
                /** Reported usage of the workspace's buckets, in bytes. */
                'bucket_usage_bytes' => $this->resource->current_bucket_usage_bytes,
                /** Worker containers of the projects that serve or are being deployed. */
                'worker_containers' => $this->resource->current_worker_containers_count,
                /** Enabled schedules that count towards `max_schedules`. */
                'schedules' => $this->resource->current_schedules_count,
                /** Managed Valkey stores that count towards `max_valkeys`. */
                'valkeys' => $this->resource->current_valkeys_count,
                /** MB of `maxmemory` the workspace's Valkey stores reserve (allocated, not used). */
                'valkey_memory_mb' => $this->resource->current_valkey_memory_mb,
                /** Custom domain claims that count towards `max_custom_domains`. */
                'custom_domains' => $this->resource->current_custom_domains_count,
            ],
        ];
    }
}
