<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Actions\Auth\GetCurrentUserAction;
use App\Actions\Profile\RemoveAvatarAction;
use App\Actions\Profile\UpdateProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

final class ProfileController extends Controller
{
    /** @scramble-return UserResource */
    public function destroyAvatar(
        Request $request,
        RemoveAvatarAction $action,
        GetCurrentUserAction $getCurrentUserAction,
    ): UserResource {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($getCurrentUserAction->handle($action->handle($user)));
    }

    public function update(
        UpdateProfileRequest $request,
        UpdateProfileAction $action,
        GetCurrentUserAction $getCurrentUserAction,
    ): UserResource {
        $user = $action->handle(
            $request->user(),
            $request->toData(),
        );

        return new UserResource(
            $getCurrentUserAction->handle($user),
        );
    }
}
