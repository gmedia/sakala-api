<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\OAuthAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;

final class HandleGitHubCallbackAction
{
    public function handle(string $code, string $state): array
    {
        // State validation is already handled in GitHubCallbackRequest
        Session::pull('github_oauth_state');

        // Get user data from GitHub via Socialite
        $githubUser = Socialite::driver('github')->user();

        // Find or create user first (to get user_id for OAuth account)
        $user = $this->findOrCreateUser($githubUser);

        // Find or create OAuth account with user_id
        $oauthAccount = OAuthAccount::updateOrCreate(
            [
                'provider' => 'github',
                'provider_user_id' => (string) $githubUser->getId(),
            ],
            [
                'user_id' => $user->id,
                'provider_username' => $githubUser->getNickname() ?? $githubUser->getName(),
                'avatar_url' => $githubUser->getAvatar(),
                'access_token' => $this->extractToken($githubUser),
                'refresh_token' => $this->extractRefreshToken($githubUser),
                'token_expires_at' => $this->extractExpiresAt($githubUser),
            ]
        );

        // Create Sanctum session token
        $token = $this->createSession($user);

        // Generate safe redirect URL to console
        $redirectUrl = $this->generateConsoleRedirectUrl($token);

        return [
            'user' => $user,
            'token' => $token,
            'redirect_url' => $redirectUrl,
        ];
    }

    private function findOrCreateUser(ProviderUser $githubUser): User
    {
        $user = User::where('email', $githubUser->getEmail())->first();

        if ($user) {
            // Update existing user with latest OAuth info
            $user->update([
                'avatar_url' => $githubUser->getAvatar(),
                'last_login_at' => now(),
            ]);

            return $user;
        }

        // Create new user
        return User::create([
            'name' => $githubUser->getName() ?? $githubUser->getNickname() ?? 'GitHub User',
            'email' => $githubUser->getEmail(),
            'avatar_url' => $githubUser->getAvatar(),
            'onboarding_source' => 'github',
            'last_login_at' => now(),
        ]);
    }

    private function createSession(User $user): string
    {
        // Login the user
        Auth::login($user);

        // Create Sanctum token for API access
        // Use Laravel's session lifetime config (in minutes) as a fallback for token expiry
        $minutes = (int) config('session.lifetime', 30);

        return $user->createToken(
            name: 'web-session',
            expiresAt: now()->addMinutes($minutes)
        )->plainTextToken;
    }

    private function extractToken(ProviderUser $user): ?string
    {
        if (isset($user->token)) {
            return $user->token;
        }

        if (isset($user->accessToken)) {
            return $user->accessToken;
        }

        if (method_exists($user, 'getAccessToken')) {
            return $user->getAccessToken();
        }

        return null;
    }

    private function extractRefreshToken(ProviderUser $user): ?string
    {
        if (isset($user->refreshToken)) {
            return $user->refreshToken;
        }

        if (isset($user->refresh_token)) {
            return $user->refresh_token;
        }

        return null;
    }

    private function extractExpiresAt(ProviderUser $user): ?CarbonInterface
    {
        if (isset($user->expiresIn) && $user->expiresIn) {
            return now()->addSeconds($user->expiresIn);
        }

        if (isset($user->expires_in) && $user->expires_in) {
            return now()->addSeconds($user->expires_in);
        }

        return null;
    }

    private function generateConsoleRedirectUrl(string $token): string
    {
        $consoleUrl = env('SAKALA_CONSOLE_URL', 'http://app.sakala.localhost:5173');

        // Use a secure redirect to console with token in fragment (frontend will read it)
        return "$consoleUrl/auth/callback?token=$token";
    }
}
