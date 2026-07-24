<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LogoutCurrentUserAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LogoutController extends Controller
{
    public function __invoke(Request $request, LogoutCurrentUserAction $logoutCurrentUser): Response
    {
        $logoutCurrentUser->handle($request->session());

        return response()->noContent();
    }
}
