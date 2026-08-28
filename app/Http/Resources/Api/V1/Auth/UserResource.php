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
            'onboarding_source' => $this->onboardingSource !== null
                ? $this->onboardingSource->value
                : null,

            'onboarding_completed_at' => $this->onboardingCompletedAt !== null
                ? $this->onboardingCompletedAt->toAtomString()
                : null,

            'last_login_at' => $this->lastLoginAt !== null
                ? $this->lastLoginAt->toAtomString()
                : null,
        ];
    }
}
