<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Workspace;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace as one of its members sees it, with that member's role.
 *
 * @mixin Workspace
 *
 * @property Workspace $resource
 */
final class WorkspaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->resource->limit_profile_id === null ? null : $this->resource->limitProfile;

        // Callers load these with withSummaryCounts(); a single workspace without them is counted here.
        $attributes = $this->resource->getAttributes();

        if (! array_key_exists('projects_count', $attributes) || ! array_key_exists('active_deployments_count', $attributes)) {
            $this->resource->loadSummaryCounts();
        }

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'type' => $this->resource->type->value,
            /**
             * The caller's role here. Owners manage members and the workspace; owners and members manage projects;
             * viewers only read.
             */
            'role' => $this->resource->membership?->role->value,
            /**
             * The limit profile attached by an admin, or null for the default of the workspace type.
             */
            'limit_profile' => $profile === null ? null : ['id' => $profile->id, 'name' => $profile->name],
            /** Projects in the workspace, not counting deleted ones waiting for purge. */
            'projects_count' => (int) $this->resource->projects_count,
            /**
             * Deployments queued or running in the workspace right now, the number its concurrent deployment limit
             * counts.
             */
            'active_deployments_count' => (int) $this->resource->active_deployments_count,
            'created_at' => $this->resource->created_at?->toAtomString(),
        ];
    }
}
