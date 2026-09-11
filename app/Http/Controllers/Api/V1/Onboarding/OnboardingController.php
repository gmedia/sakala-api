<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Actions\Auth\GetCurrentUserAction;
use App\Actions\Onboarding\CompleteOnboardingAction;
use App\Actions\Onboarding\StoreOnboardingProfileAction;
use App\Actions\Onboarding\StoreOnboardingSourceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Onboarding\StoreOnboardingProfileRequest;
use App\Http\Requests\Api\V1\Onboarding\StoreOnboardingSourceRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;

final class OnboardingController extends Controller
{
    /** @scramble-return UserResource */
    public function source(
        StoreOnboardingSourceRequest $request,
        StoreOnboardingSourceAction $storeOnboardingSource,
        GetCurrentUserAction $getCurrentUser,
    ): UserResource {
        /** @var User $user */
        $user = $request->user();

        $updatedUser = $storeOnboardingSource->handle($user, $request->toData());

        return UserResource::make($getCurrentUser->handle($updatedUser));
    }

    /** @scramble-return UserResource */
    public function profile(
        StoreOnboardingProfileRequest $request,
        StoreOnboardingProfileAction $storeOnboardingProfile,
        GetCurrentUserAction $getCurrentUser,
    ): UserResource {
        /** @var User $user */
        $user = $request->user();

        $updatedUser = $storeOnboardingProfile->handle($user, $request->toData());

        return UserResource::make($getCurrentUser->handle($updatedUser));
    }

    /** @scramble-return UserResource */
    public function complete(
        CompleteOnboardingAction $action,
        GetCurrentUserAction $getCurrentUser,
    ): UserResource {
        /** @var User $user */
        $user = request()->user();

        $updatedUser = $action->handle($user);

        return UserResource::make(
            $getCurrentUser->handle($updatedUser)
        );
    }
}
