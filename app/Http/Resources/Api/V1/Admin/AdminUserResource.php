<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\User;
use App\Support\User\AvatarUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as admins see it. Never carries the password hash, remember
 * token, OAuth tokens, or notification settings.
 *
 * @mixin User
 *
 * @property User $resource
 */
final class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'avatar_url' => app(AvatarUrlResolver::class)->resolve($user),
            /** `user` or `admin`. */
            'role' => $user->role->value,
            'email_verified' => $user->email_verified_at !== null,
            'email_verified_at' => $user->email_verified_at?->toAtomString(),
            /**
             * The answer to the onboarding question about where they heard of Sakala, or null.
             *
             * @var string|null
             */
            'onboarding_source' => $user->onboarding_source?->value,
            'onboarding_completed_at' => $user->onboarding_completed_at?->toAtomString(),
            'last_login_at' => $user->last_login_at?->toAtomString(),
            /**
             * The per-user cap on owned teams set by an admin, or null for the configured default.
             */
            'max_owned_teams' => $user->max_owned_teams,
            /**
             * Projects this user created, in any workspace.
             *
             * @var int|null
             */
            'created_projects_count' => $this->whenCounted('projects'),
            'created_at' => $user->created_at?->toAtomString(),
        ];
    }
}
