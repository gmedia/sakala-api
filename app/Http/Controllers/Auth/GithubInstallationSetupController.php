<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\GithubInstallationFailure;
use App\Http\Controllers\Controller;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use App\Services\GitHub\GithubInstallationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Browsers land here from GitHub, so every outcome goes back to the wizard the
 * user left, with the result in the query, never to a raw API error page.
 */
final class GithubInstallationSetupController extends Controller
{
    public function __invoke(Request $request, GithubInstallationService $installations, ConsoleAuthenticationRedirect $redirect): RedirectResponse
    {
        $expectedState = $request->session()->pull('github_app_installation_state');
        $providedState = $request->query('state');
        $id = $request->integer('installation_id');
        $configuredInstallationId = $request->session()->pull('github_app_configure_installation_id');
        $validState = is_string($expectedState) && $expectedState !== '' && is_string($providedState) && $providedState !== '' && hash_equals($expectedState, $providedState);
        $validConfiguration = is_int($configuredInstallationId) && $configuredInstallationId === $id;

        if (! $validState && ! $validConfiguration) {
            return redirect()->away($redirect->githubInstallationError(GithubInstallationFailure::InvalidState));
        }

        // GitHub sends no installation ID when an organization owner still has
        // to approve the request.
        if ($id < 1) {
            return redirect()->away($redirect->githubInstallationError(GithubInstallationFailure::InvalidInstallation));
        }

        try {
            $installations->setup($request->user(), $id);
        } catch (AuthorizationException|HttpResponseException) {
            return redirect()->away($redirect->githubInstallationError(GithubInstallationFailure::AccessDenied));
        } catch (Throwable $exception) {
            Log::warning('GitHub App installation setup failed.', ['error' => $exception->getMessage()]);

            return redirect()->away($redirect->githubInstallationError(GithubInstallationFailure::ProviderFailure));
        }

        return redirect()->away($redirect->githubInstallationSuccess());
    }
}
