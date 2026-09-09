<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\CurrentUserData;
use App\Models\User;
use App\Support\User\AvatarUrlResolver;

final class GetCurrentUserAction
{
    public function __construct(
        private AvatarUrlResolver $avatarUrlResolver,
    ) {}

    public function handle(User $user): CurrentUserData
    {
        return new CurrentUserData(
            id: $user->id,
            name: $user->name,
            username: $user->username,
            email: $user->email,
            avatarUrl: $this->avatarUrlResolver->resolve($user),
            role: $user->role,
            onboardingSource: $user->onboarding_source,
            onboardingCompletedAt: $user->onboarding_completed_at,
            lastLoginAt: $user->last_login_at,
        );
    }
}
