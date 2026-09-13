<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\User;

final class CompleteOnboardingAction
{
    public function handle(User $user): User
    {
        User::query()
            ->whereKey($user->getKey())
            ->whereNull('onboarding_completed_at')
            ->update([
                'onboarding_completed_at' => now(),
            ]);

        return $user->fresh() ?? $user;
    }
}
