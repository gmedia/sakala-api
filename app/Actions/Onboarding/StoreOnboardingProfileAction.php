<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Data\Onboarding\StoreOnboardingProfileData;
use App\Models\User;

final class StoreOnboardingProfileAction
{
    public function handle(User $user, StoreOnboardingProfileData $data): User
    {
        $user->forceFill([
            'name' => $data->skip ? $user->name : $data->name,
            'onboarding_role' => $data->skip ? null : $data->role,
        ])->save();

        return $user->fresh() ?? $user;
    }
}
