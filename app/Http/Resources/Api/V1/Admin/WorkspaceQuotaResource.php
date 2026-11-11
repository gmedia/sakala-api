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
                 * Reserved for the build rate limit; not enforced yet.
                 */
                'max_builds_per_hour' => $limits->max_builds_per_hour,
                /** Global, the same for every workspace. */
                'max_active_deployments_per_project' => $this->resource->max_active_deployments_per_project,
            ],
            'usage' => [
                'projects' => $this->resource->current_projects_count,
                /** Projects that serve or are being deployed. */
                'active_containers' => $this->resource->current_active_containers_count,
                'active_deployments' => $this->resource->current_active_deployments_count,
            ],
        ];
    }
}
