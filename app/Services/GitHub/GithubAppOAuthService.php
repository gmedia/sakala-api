<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\Auth\GithubOAuthIdentityData;
use App\Enums\GithubOAuthFailure;
use App\Exceptions\Auth\GithubOAuthIdentityException;
use App\Models\OAuthAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class GithubAppOAuthService
{
    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private const API_URL = 'https://api.github.com';

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(64);
        $request->session()->put('github_app_oauth_state', $state);

        return redirect()->away(self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->config('redirect'),
            'state' => $state,
        ], encoding_type: PHP_QUERY_RFC3986));
    }

    public function identityFromCallback(Request $request): GithubOAuthIdentityData
    {
        $expectedState = $request->session()->pull('github_app_oauth_state');
        $providedState = $request->query('state');
        if (! is_string($expectedState) || $expectedState === '' || ! is_string($providedState) || $providedState === '' || ! hash_equals($expectedState, $providedState)) {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::InvalidState);
        }

        $code = $request->string('code')->toString();
        if ($code === '') {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::ProviderFailure);
        }

        $token = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'code' => $code,
            'redirect_uri' => $this->config('redirect'),
        ])->throw()->json();

        $accessToken = $token['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::ProviderFailure);
        }

        $profile = Http::withToken($accessToken)->acceptJson()->get(self::API_URL.'/user')->throw()->json();
        $emails = Http::withToken($accessToken)->acceptJson()->get(self::API_URL.'/user/emails')->throw()->json();
        if (! is_array($profile) || ! is_array($emails)) {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::ProviderFailure);
        }

        $email = collect($emails)->first(fn (mixed $item): bool => is_array($item) && ($item['primary'] ?? false) && ($item['verified'] ?? false));
        if (! is_array($email) || ! is_string($email['email'] ?? null)) {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::EmailUnavailable);
        }

        return GithubOAuthIdentityData::fromGithubProfile(
            profile: $profile,
            email: $email['email'],
            accessToken: $accessToken,
            refreshToken: is_string($token['refresh_token'] ?? null) ? $token['refresh_token'] : null,
            expiresIn: is_int($token['expires_in'] ?? null) ? $token['expires_in'] : null,
        );
    }

    public function accessToken(OAuthAccount $account): string
    {
        if ($account->token_expires_at === null || $account->token_expires_at->isAfter(now()->addMinute())) {
            return $account->access_token;
        }

        if (blank($account->refresh_token)) {
            throw new \RuntimeException('GitHub authorization has expired. Please sign in again.');
        }

        $token = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ])->throw()->json();

        if (! is_string($token['access_token'] ?? null)) {
            throw new \RuntimeException('GitHub token refresh failed.');
        }

        $account->update([
            'access_token' => $token['access_token'],
            'refresh_token' => is_string($token['refresh_token'] ?? null) ? $token['refresh_token'] : null,
            'token_expires_at' => is_int($token['expires_in'] ?? null) ? now()->addSeconds($token['expires_in']) : null,
        ]);

        return $account->access_token;
    }

    private function config(string $key): string
    {
        $value = config("services.github_app.{$key}");
        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("GitHub App {$key} is not configured.");
        }

        return $value;
    }
}
