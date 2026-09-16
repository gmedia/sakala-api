<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResendVerificationNotificationAction;
use App\Data\Auth\VerificationNotificationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResendVerificationNotificationRequest;
use App\Http\Resources\Api\V1\Auth\VerificationNotificationResource;
use Illuminate\Http\JsonResponse;

final class ResendVerificationNotificationController extends Controller
{
    /** @scramble-return 202 VerificationNotificationResource */
    public function __invoke(
        ResendVerificationNotificationRequest $request,
        ResendVerificationNotificationAction $action,
    ): JsonResponse {
        $action->handle($request->toData());

        return VerificationNotificationResource::make(new VerificationNotificationData)
            ->response()
            ->setStatusCode(202);
    }
}
