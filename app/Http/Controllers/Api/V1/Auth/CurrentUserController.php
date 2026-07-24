<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GetCurrentUserAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

final class CurrentUserController extends Controller
{
    /** @scramble-return UserResource */
    public function __invoke(Request $request, GetCurrentUserAction $getCurrentUser): UserResource
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return UserResource::make($getCurrentUser->handle($user));
    }
}
