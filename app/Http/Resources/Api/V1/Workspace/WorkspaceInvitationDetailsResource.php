<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Workspace;

use App\Data\Workspace\WorkspaceInvitationDetailsData;
use App\Enums\WorkspaceRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An open invitation for the holder of its link. Unlike WorkspaceInvitationResource it never carries the invited
 * address, because the holder may be a different account.
 *
 * @property WorkspaceInvitationDetailsData $resource
 */
final class WorkspaceInvitationDetailsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource->invitation;
        $workspace = $invitation->workspace;
        $inviter = $invitation->inviter;

        return [
            'id' => $invitation->id,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toAtomString(),
            'created_at' => $invitation->created_at?->toAtomString(),
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
            /**
             * True for an owner invitation: only the account whose verified email is the invited address can accept
             * it. The address itself is not shown.
             */
            'restricted_to_invited_email' => $invitation->role === WorkspaceRole::Owner,
            /** Whether this account can accept it right now. */
            'can_accept' => $this->resource->accept_error_code === null,
            /**
             * The code accepting would answer now, or null when `can_accept` is true.
             *
             * @var 'INVITATION_EMAIL_MISMATCH'|'ALREADY_WORKSPACE_MEMBER'|'TEAM_LIMIT_EXCEEDED'|null
             */
            'accept_error_code' => $this->resource->accept_error_code,
        ];
    }
}
