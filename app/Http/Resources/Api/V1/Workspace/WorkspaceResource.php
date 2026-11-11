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
            'created_at' => $this->resource->created_at?->toAtomString(),
        ];
    }
}
