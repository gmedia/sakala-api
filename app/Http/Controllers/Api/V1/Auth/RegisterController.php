<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\Auth\RegistrationResource;
use Illuminate\Http\JsonResponse;

final class RegisterController extends Controller
{
    /** @scramble-return 201 RegistrationResource */
    public function __invoke(
        RegisterRequest $request,
        RegisterAction $action,
    ): JsonResponse {
        $user = $action->handle($request->toData());

        return RegistrationResource::make($user)
            ->response()
            ->setStatusCode(201);
    }
}
