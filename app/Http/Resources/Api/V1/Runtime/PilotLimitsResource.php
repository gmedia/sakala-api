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
        return [
            'quotas' => [
                'max_projects_per_user' => $this->resource->max_projects_per_user,
                'max_active_deployments_per_user' => $this->resource->max_active_deployments_per_user,
                'max_active_deployments_per_project' => $this->resource->max_active_deployments_per_project,
                'current_projects_count' => $this->resource->current_projects_count,
                'current_active_deployments_count' => $this->resource->current_active_deployments_count,
            ],
            'runtime_defaults' => [
                'memory_mb' => (int) config('sakala.pilot_limits.resources.default_memory_mb', 256),
                'cpu_millis' => (int) config('sakala.pilot_limits.resources.default_cpu_millis', 500),
                'pids_limit' => (int) config('sakala.pilot_limits.resources.default_pids_limit', 128),
            ],
            'runtime_maximums' => [
                'memory_mb' => (int) config('sakala.pilot_limits.resources.max_memory_mb', 512),
                'cpu_millis' => (int) config('sakala.pilot_limits.resources.max_cpu_millis', 1000),
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
            ],
        ];
    }
}
