<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GetCurrentUserAction;
use App\Actions\Auth\RequestEmailChangeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RequestEmailChangeRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class EmailChangeController extends Controller
{
    public function __invoke(
        RequestEmailChangeRequest $request,
        RequestEmailChangeAction $action,
        GetCurrentUserAction $getCurrentUser,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($getCurrentUser->handle($action->handle($user, $request->newEmail())))
            ->response()
            ->setStatusCode(202);
    }
}
