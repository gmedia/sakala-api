<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GetAuthOptionsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Auth\AuthOptionsResource;

final class AuthOptionsController extends Controller
{
    /**
     * Show which sign-in options this deployment can actually honour.
     *
     * Public, because the signed-out login page reads it to decide what to offer.
     */
    public function __invoke(GetAuthOptionsAction $getAuthOptions): AuthOptionsResource
    {
        return AuthOptionsResource::make($getAuthOptions->handle());
    }
}
