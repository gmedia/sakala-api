<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GetCurrentUserAction;
use App\Actions\Auth\LoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;

final class LoginController extends Controller
{
    /** @scramble-return UserResource */
    public function __invoke(
        LoginRequest $request,
        LoginAction $login,
        GetCurrentUserAction $getCurrentUser,
    ): UserResource {
        $user = $login->handle($request->toData());
        $request->session()->regenerate();

        return UserResource::make($getCurrentUser->handle($user));
    }
}
