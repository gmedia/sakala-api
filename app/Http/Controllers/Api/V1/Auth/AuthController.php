<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\HandleGitHubCallbackAction;
use App\Actions\Auth\RedirectToGitHubAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\GitHubCallbackRequest;
use App\Http\Resources\Api\V1\Auth\AuthCallbackResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class AuthController extends Controller
{
    /**
     * Redirect user to GitHub OAuth consent page.
     */
    public function redirectToGitHub(RedirectToGitHubAction $action): RedirectResponse
    {
        $redirectUrl = $action->handle();

        return redirect()->away($redirectUrl);
    }

    /**
     * Handle GitHub OAuth callback and create session.
     */
    public function handleGitHubCallback(
        GitHubCallbackRequest $request,
        HandleGitHubCallbackAction $action
    ): JsonResponse {
        $data = $action->handle(
            code: $request->validated('code'),
            state: $request->validated('state')
        );

        return AuthCallbackResource::make($data)->response();
    }
}
