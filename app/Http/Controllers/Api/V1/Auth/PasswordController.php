<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ChangePasswordAction;
use App\Actions\Auth\GetCurrentUserAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Data\Auth\VerificationNotificationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Http\Resources\Api\V1\Auth\VerificationNotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class PasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request, SendPasswordResetLinkAction $action): JsonResponse
    {
        $action->handle($request->email());

        return VerificationNotificationResource::make(new VerificationNotificationData)
            ->response()
            ->setStatusCode(202);
    }

    public function reset(ResetPasswordRequest $request, ResetPasswordAction $action): Response
    {
        $action->handle($request->toData());

        return response()->noContent();
    }

    /** @scramble-return UserResource */
    public function update(
        ChangePasswordRequest $request,
        ChangePasswordAction $action,
        GetCurrentUserAction $getCurrentUser,
    ): UserResource {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($getCurrentUser->handle($action->handle($user, $request->newPassword())));
    }
}
