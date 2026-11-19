<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Runtime;

use App\Data\Runtime\PilotQuotaLimitsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PilotQuotaLimitsData
 *
 * @property PilotQuotaLimitsData $resource
 */
final class PilotLimitsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $limits = $this->resource->limits;

        return [
            /** The workspace these quotas and maximums belong to. */
            'workspace_id' => $this->resource->workspace_id,
            /**
             * The limit profile attached to the workspace, or null when it runs under the default for its type.
             */
            'limit_profile' => $limits->profile === null ? null : [
                'id' => $limits->profile->id,
                'name' => $limits->profile->name,
            ],
            'quotas' => [
                'max_projects_per_user' => $this->resource->max_projects_per_user,
                'max_active_deployments_per_user' => $this->resource->max_active_deployments_per_user,
                'max_active_deployments_per_project' => $this->resource->max_active_deployments_per_project,
                'current_projects_count' => $this->resource->current_projects_count,
                'current_active_deployments_count' => $this->resource->current_active_deployments_count,
                'max_active_containers' => $this->resource->max_active_containers,
                /** Replicas of the projects that serve or are being deployed; sleeping projects do not count. */
                'current_active_containers_count' => $this->resource->current_active_containers_count,
                /** Most replicas per project, from the workspace's limit profile; 1 without one. */
                'max_replicas' => $limits->max_replicas,
                /**
                 * Deployments the workspace may create per rolling hour, from its limit profile or the global
                 * setting; every deployment builds. `null` means no limit.
                 */
                'max_builds_per_hour' => $limits->max_builds_per_hour,
                /** Managed databases the workspace may hold, from its limit profile or the global setting; `0` allows none. */
                'max_databases' => $limits->max_databases,
                /** Managed databases that count towards `max_databases`: every live one except those whose creation failed. */
                'current_databases_count' => $this->resource->current_databases_count,
                /**
                 * Total size the workspace's managed databases may reach, in MB. At 80 % its owners and members are
                 * notified; at 100 % every database of the workspace is locked. `0` allows none.
                 */
                'max_database_storage_mb' => $limits->max_database_storage_mb,
                /** Size of the workspace's managed databases from the database node's last report, in bytes. */
                'current_database_storage_bytes' => $this->resource->current_database_storage_bytes,
            ],
            'runtime_defaults' => [
                /** @var int */
                'memory_mb' => min((int) config('sakala.pilot_limits.resources.default_memory_mb', 256), $limits->max_memory_mb),
                /** @var int */
                'cpu_millis' => min((int) config('sakala.pilot_limits.resources.default_cpu_millis', 500), $limits->max_cpu_millis),
                'pids_limit' => (int) config('sakala.pilot_limits.resources.default_pids_limit', 128),
            ],
            'runtime_maximums' => [
                'memory_mb' => $limits->max_memory_mb,
                'cpu_millis' => $limits->max_cpu_millis,
                'pids_limit' => (int) config('sakala.pilot_limits.resources.max_pids_limit', 256),
            ],
            'timeouts' => [
                'build_timeout_seconds' => (int) config('sakala.pilot_limits.timeouts.build_timeout_seconds', 600),
                'start_timeout_seconds' => (int) config('sakala.pilot_limits.timeouts.start_timeout_seconds', 120),
                'command_timeout_seconds' => (int) config('sakala.pilot_limits.timeouts.command_timeout_seconds', 900),
            ],
            'log_bounds' => [
                'max_line_length' => (int) config('sakala.pilot_limits.log_bounds.max_line_length', 4096),
                'max_batch_lines' => (int) config('sakala.pilot_limits.log_bounds.max_batch_lines', 500),
                'max_total_bytes' => (int) config('sakala.pilot_limits.log_bounds.max_total_bytes', 10 * 1024 * 1024),
                'max_request_bytes' => (int) config('sakala.pilot_limits.log_bounds.max_request_bytes', 1024 * 1024),
            ],
            'deleted_project_retention_days' => (int) config('sakala.project.deleted_retention_days', 30),
            /**
             * Minutes without a request before a running project of this workspace is put to sleep, from its
             * limit profile or the global setting. `0` means its projects never sleep.
             */
            'project_idle_sleep_minutes' => $limits->idle_sleep_minutes,
        ];
    }
}
