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
    /**
     * Always 202 with the same body, whether or not the email already has an
     * account, so this endpoint cannot be used to learn which emails exist.
     *
     * @scramble-return 202 RegistrationResource
     */
    public function __invoke(
        RegisterRequest $request,
        RegisterAction $action,
    ): JsonResponse {
        $data = $request->toData();

        $action->handle($data);

        return RegistrationResource::make($data)
            ->response()
            ->setStatusCode(202);
    }
}
