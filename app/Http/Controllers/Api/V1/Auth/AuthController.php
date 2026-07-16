<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\HandleGitHubCallbackAction;
use App\Actions\Auth\RedirectToGitHubAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\GitHubCallbackRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class AuthController extends Controller
{
    public function redirectToGitHub(RedirectToGitHubAction $action): RedirectResponse
    {
        $redirectUrl = $action->handle();

        return redirect()->away($redirectUrl);
    }

    /**
     * Log the user out and invalidate the session.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function handleGitHubCallback(
        GitHubCallbackRequest $request,
        HandleGitHubCallbackAction $action
    ): RedirectResponse {
        $redirectUrl = $action->handle(
            code: $request->validated('code'),
            state: $request->validated('state'),
            request: $request,
        );

        return redirect()->away($redirectUrl);
    }
}
