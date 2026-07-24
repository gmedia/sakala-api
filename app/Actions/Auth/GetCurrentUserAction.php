<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\CurrentUserData;
use App\Models\User;

final class GetCurrentUserAction
{
    public function handle(User $user): CurrentUserData
    {
        return new CurrentUserData(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            avatarUrl: $user->avatar_url,
            role: $user->role,
            onboardingSource: $user->onboarding_source,
            onboardingCompletedAt: $user->onboarding_completed_at,
            lastLoginAt: $user->last_login_at,
        );
    }
}
