<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\User;

final class CompleteOnboardingAction
{
    public function handle(User $user): User
    {
        $user->forceFill([
            'onboarding_completed_at' => $user->onboarding_completed_at ?? now(),
        ])->save();

        return $user->fresh() ?? $user;
    }
}
