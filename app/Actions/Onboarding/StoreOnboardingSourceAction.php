<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Data\Onboarding\StoreOnboardingSourceData;
use App\Models\User;
use Illuminate\Support\Carbon;

final class StoreOnboardingSourceAction
{
    public function handle(User $user, StoreOnboardingSourceData $data): User
    {
        $now = Carbon::now();

        if ($data->skip) {
            $user->forceFill([
                'onboarding_source' => null,
                'onboarding_completed_at' => $user->onboarding_completed_at ?? $now,
            ])->save();
        } else {
            $user->forceFill([
                'onboarding_source' => $data->source,
                'onboarding_completed_at' => $user->onboarding_completed_at ?? $now,
            ])->save();
        }

        return $user->fresh() ?? $user;
    }
}
