<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\AdminUserDetailData;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AdminUserDetailData $resource
 */
final class AdminUserDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'user' => new AdminUserResource($data->user),
            /** Teams this user owns now, counted against the cap. */
            'owned_teams_count' => $data->ownedTeamsCount,
            /** The cap in force: `user.max_owned_teams`, or the configured default when that is null. Admins are not capped. */
            'effective_max_owned_teams' => $data->user->maxOwnedTeams(),
            /**
             * Every workspace the user belongs to, the personal one first.
             *
             * @var list<array{id: string, name: string, slug: string, type: string, role: string|null, projects_count: int, limit_profile: array{id: string, name: string}|null}>
             */
            'workspaces' => $data->workspaces->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'type' => $workspace->type->value,
                'role' => $workspace->membership?->role->value,
                'projects_count' => (int) $workspace->getAttribute('projects_count'),
                'limit_profile' => $workspace->limitProfile === null ? null : ['id' => $workspace->limitProfile->id, 'name' => $workspace->limitProfile->name],
            ])->values()->all(),
            /** Limits and usage of the personal workspace; null only for an account without one. */
            'personal_workspace_quota' => $data->personalQuota === null ? null : new WorkspaceQuotaResource($data->personalQuota),
            /** The 20 newest audit events where this user is the actor or the subject. */
            'recent_audit_events' => AuditEventResource::collection($data->recentAuditEvents),
        ];
    }
}
