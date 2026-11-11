<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workspace
 *
 * @property Workspace $resource
 */
final class AdminWorkspaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $workspace = $this->resource;
        $profile = $workspace->limit_profile_id === null ? null : $workspace->limitProfile;
        $personalUser = $workspace->personal_user_id === null ? null : $workspace->personalUser;

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            /** `personal` or `team`. */
            'type' => $workspace->type->value,
            /**
             * The attached limit profile, or null for the default of the workspace type.
             */
            'limit_profile' => $profile === null ? null : ['id' => $profile->id, 'name' => $profile->name],
            /**
             * The account a personal workspace belongs to; null for a team.
             */
            'personal_user' => $personalUser === null ? null : [
                'id' => $personalUser->id,
                'name' => $personalUser->name,
                'username' => $personalUser->username,
            ],
            /** @var int|null */
            'members_count' => $this->whenCounted('members'),
            /** @var int|null */
            'projects_count' => $this->whenCounted('projects'),
            'created_at' => $workspace->created_at?->toAtomString(),
        ];
    }
}
