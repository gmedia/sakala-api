<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as ProviderUser;

final class SyncGitHubUserAction
{
    public function handle(ProviderUser $githubUser): User
    {
        // Check for existing OAuth account first (prioritize provider + provider_user_id)
        $oauthAccount = OAuthAccount::where('provider', 'github')
            ->where('provider_user_id', (string) $githubUser->getId())
            ->first();

        if ($oauthAccount) {
            // Update user with latest info from GitHub
            $oauthAccount->user->update([
                'avatar_url' => $githubUser->getAvatar(),
                'last_login_at' => now(),
            ]);

            return $oauthAccount->user;
        }

        // Fallback: check for existing user by email
        if (! $githubUser->getEmail()) {
            throw new ValidationException(
                validator: Validator::make([], []),
                response: response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'email' => ['GitHub email is private or not provided.'],
                    ],
                ], 422)
            );
        }

        $user = User::where('email', $githubUser->getEmail())->first();
        if ($user) {
            // Update existing user with latest info from GitHub
            $user->update([
                'avatar_url' => $githubUser->getAvatar(),
                'last_login_at' => now(),
            ]);

            // Create OAuth account for the existing user
            OAuthAccount::create([
                'user_id' => $user->id,
                'provider' => 'github',
                'provider_user_id' => (string) $githubUser->getId(),
                'provider_username' => $githubUser->getNickname() ?? $githubUser->getName(),
                'avatar_url' => $githubUser->getAvatar(),
                'access_token' => $this->extractToken($githubUser),
                'refresh_token' => $this->extractRefreshToken($githubUser),
                'token_expires_at' => $this->extractExpiresAt($githubUser),
            ]);

            return $user;
        }

        // Create new user and OAuth account
        $user = User::create([
            'name' => $githubUser->getName() ?? $githubUser->getNickname() ?? 'GitHub User',
            'email' => $githubUser->getEmail(),
            'avatar_url' => $githubUser->getAvatar(),
            'onboarding_source' => 'github',
            'last_login_at' => now(),
        ]);

        OAuthAccount::create([
            'user_id' => $user->id,
            'provider' => 'github',
            'provider_user_id' => (string) $githubUser->getId(),
            'provider_username' => $githubUser->getNickname() ?? $githubUser->getName(),
            'avatar_url' => $githubUser->getAvatar(),
            'access_token' => $this->extractToken($githubUser),
            'refresh_token' => $this->extractRefreshToken($githubUser),
            'token_expires_at' => $this->extractExpiresAt($githubUser),
        ]);

        return $user;
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

    private function extractExpiresAt(ProviderUser $user): ?string
    {
        if (isset($user->expiresIn) && $user->expiresIn) {
            return now()->addSeconds($user->expiresIn)->toDateTimeString();
        }

        if (isset($user->expires_in) && $user->expires_in) {
            return now()->addSeconds($user->expires_in)->toDateTimeString();
        }

        return null;
    }
}
