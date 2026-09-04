<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SyncGoogleOAuthIdentityAction;
use App\Data\Auth\GoogleOAuthIdentityData;
use App\Enums\GoogleOAuthFailure;
use App\Exceptions\Auth\GoogleOAuthIdentityException;
use App\Http\Controllers\Controller;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

final class GoogleCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        SyncGoogleOAuthIdentityAction $syncGoogleOAuthIdentity,
        ConsoleAuthenticationRedirect $consoleAuthenticationRedirect,
    ): RedirectResponse {
        if ($request->query('error') !== null) {
            return $this->redirectToLoginError(
                $consoleAuthenticationRedirect,
                $request->query('error') === 'access_denied'
                    ? GoogleOAuthFailure::AccessDenied
                    : GoogleOAuthFailure::ProviderFailure,
            );
        }

        try {
            $socialiteUser = Socialite::driver('google')->user();

            if (! $socialiteUser instanceof SocialiteUser) {
                throw new GoogleOAuthIdentityException(GoogleOAuthFailure::ProviderFailure);
            }

            $identity = GoogleOAuthIdentityData::fromSocialiteUser($socialiteUser);
            $user = $syncGoogleOAuthIdentity->handle($identity);
        } catch (GoogleOAuthIdentityException $exception) {
            return $this->redirectToLoginError(
                $consoleAuthenticationRedirect,
                $exception->failure,
            );
        } catch (InvalidStateException) {
            return $this->redirectToLoginError(
                $consoleAuthenticationRedirect,
                GoogleOAuthFailure::InvalidState,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToLoginError(
                $consoleAuthenticationRedirect,
                GoogleOAuthFailure::ProviderFailure,
            );
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->away($consoleAuthenticationRedirect->dashboard());
    }

    private function redirectToLoginError(
        ConsoleAuthenticationRedirect $consoleAuthenticationRedirect,
        GoogleOAuthFailure $failure,
    ): RedirectResponse {
        return redirect()->away($consoleAuthenticationRedirect->loginError($failure));
    }
}
