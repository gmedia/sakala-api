<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Auth\UserResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;

final class CurrentUserController extends Controller
{
    /**
     * Get the authenticated user.
     *
     * @response {
     *   "data": {
     *     "id": "string",
     *     "name": "string",
     *     "email": "string",
     *     "avatar_url": "string|null"
     *   }
     * }
     */
    #[Response(status: 401, description: 'Unauthenticated.')]
    public function __invoke(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
