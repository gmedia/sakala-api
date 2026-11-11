<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\WorkspaceMember;
use App\Support\User\AvatarUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkspaceMember
 *
 * @property WorkspaceMember $resource
 */
final class WorkspaceMemberResource extends JsonResource
{
    private bool $showEmail = false;

    /** Owners invite by email, so they see the members' addresses; others do not. */
    public function withEmail(bool $showEmail): self
    {
        $this->showEmail = $showEmail;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource->user;

        return [
            /** The membership ID used by PATCH and DELETE on members. */
            'id' => $this->resource->id,
            'role' => $this->resource->role->value,
            'joined_at' => $this->resource->created_at?->toAtomString(),
            /** @var array{id: int, name: string, username: string, avatar_url: string|null, email?: string} */
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => app(AvatarUrlResolver::class)->resolve($user),
                ...($this->showEmail ? ['email' => $user->email] : []),
            ],
        ];
    }

    public static function canSeeEmails(?WorkspaceRole $viewerRole): bool
    {
        return $viewerRole === WorkspaceRole::Owner;
    }
}
