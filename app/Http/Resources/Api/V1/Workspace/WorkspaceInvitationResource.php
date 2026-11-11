<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Workspace;

use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pending invitation, for the workspace's owners and for the invited person.
 * The token is never part of it.
 *
 * @mixin WorkspaceInvitation
 *
 * @property WorkspaceInvitation $resource
 */
final class WorkspaceInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $workspace = $this->resource->workspace;
        $inviter = $this->resource->inviter;

        return [
            'id' => $this->resource->id,
            /** Lowercased. */
            'email' => $this->resource->email,
            'role' => $this->resource->role->value,
            'expires_at' => $this->resource->expires_at->toAtomString(),
            'created_at' => $this->resource->created_at?->toAtomString(),
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            /**
             * Null once the inviter's account is deleted.
             *
             * @var array{id: int, name: string, username: string}|null
             */
            'invited_by' => $inviter === null ? null : [
                'id' => $inviter->id,
                'name' => $inviter->name,
                'username' => $inviter->username,
            ],
        ];
    }
}
