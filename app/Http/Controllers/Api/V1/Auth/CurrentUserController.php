<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Auth\UserResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentUserController extends Controller
{
    /**
     * Get the authenticated user.
     */
    #[Response(type: UserResource::class, description: 'The currently authenticated user.')]
    public function __invoke(Request $request): JsonResponse
    {
        return UserResource::make($request->user())->response();
    }
}
