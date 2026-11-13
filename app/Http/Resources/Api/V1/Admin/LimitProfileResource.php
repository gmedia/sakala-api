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
             * Reserved for the build rate limit; not enforced yet.
             */
            'max_builds_per_hour' => $profile->max_builds_per_hour,
            /**
             * Minutes without a request before a project sleeps. `0` never sleeps; `null` follows the global
             * `SAKALA_PROJECT_IDLE_SLEEP_MINUTES`.
             */
            'idle_sleep_minutes' => $profile->idle_sleep_minutes,
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
