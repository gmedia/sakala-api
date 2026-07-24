<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Auth;

use App\Data\Auth\CurrentUserData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CurrentUserData */
final class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl,
            'role' => $this->role->value,
            'onboarding_source' => $this->onboardingSource?->value,
            'onboarding_completed_at' => $this->onboardingCompletedAt?->toAtomString(),
            'last_login_at' => $this->lastLoginAt?->toAtomString(),
        ];
    }
}
