<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SyncGithubOAuthIdentityAction;
use App\Enums\GithubOAuthFailure;
use App\Exceptions\Auth\GithubOAuthIdentityException;
use App\Http\Controllers\Controller;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use App\Services\GitHub\GithubAppOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

final class GithubCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        SyncGithubOAuthIdentityAction $syncGithubOAuthIdentity,
        ConsoleAuthenticationRedirect $consoleAuthenticationRedirect,
        GithubAppOAuthService $oauth,
    ): RedirectResponse {
        if ($request->query('error') !== null) {
            return $this->redirectToOAuthError(
                $consoleAuthenticationRedirect,
                $request->query('error') === 'access_denied'
                    ? GithubOAuthFailure::AccessDenied
                    : GithubOAuthFailure::ProviderFailure,
            );
        }

        try {
            $identity = $oauth->identityFromCallback($request);
            $user = $syncGithubOAuthIdentity->handle($identity);
        } catch (GithubOAuthIdentityException $exception) {
            return $this->redirectToOAuthError(
                $consoleAuthenticationRedirect,
                $exception->failure,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToOAuthError(
                $consoleAuthenticationRedirect,
                GithubOAuthFailure::ProviderFailure,
            );
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->away($consoleAuthenticationRedirect->githubOAuthCallback());
    }

    private function redirectToOAuthError(
        ConsoleAuthenticationRedirect $consoleAuthenticationRedirect,
        GithubOAuthFailure $failure,
    ): RedirectResponse {
        return redirect()->away($consoleAuthenticationRedirect->githubOAuthCallbackError($failure));
    }
}
