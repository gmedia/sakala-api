<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Data\Onboarding\StoreOnboardingSourceData;
use App\Models\User;

final class StoreOnboardingSourceAction
{
    public function handle(User $user, StoreOnboardingSourceData $data): User
    {
        $user->forceFill([
            'onboarding_source' => $data->skip ? null : $data->source,
        ])->save();

        return $user->fresh() ?? $user;
    }
}
