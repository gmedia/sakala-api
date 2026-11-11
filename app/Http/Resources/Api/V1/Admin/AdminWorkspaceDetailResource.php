<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\AdminWorkspaceDetailData;
use App\Models\Project;
use App\Models\WorkspaceMember;
use App\Support\User\AvatarUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AdminWorkspaceDetailData $resource
 */
final class AdminWorkspaceDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $avatars = app(AvatarUrlResolver::class);

        return [
            'workspace' => new AdminWorkspaceResource($data->workspace),
            /**
             * Members, oldest first. `id` is the membership ID.
             */
            'members' => $data->members->map(fn (WorkspaceMember $member): array => [
                'id' => $member->id,
                'role' => $member->role->value,
                'joined_at' => $member->created_at?->toAtomString(),
                'user' => [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                    'username' => $member->user->username,
                    'email' => $member->user->email,
                    'avatar_url' => $avatars->resolve($member->user),
                ],
            ])->values()->all(),
            /**
             * Projects owned by the workspace, newest first. Deleted projects are not listed.
             *
             * @var list<array{id: string, name: string, slug: string, status: string, runtime_status: string, created_at: string|null}>
             */
            'projects' => $data->projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status->value,
                'runtime_status' => $project->runtime_status->value,
                'created_at' => $project->created_at?->toAtomString(),
            ])->values()->all(),
            'quota' => new WorkspaceQuotaResource($data->quota),
        ];
    }
}
