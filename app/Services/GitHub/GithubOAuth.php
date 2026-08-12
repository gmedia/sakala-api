<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\OAuthAccount;
use App\Models\User;
use RuntimeException;

final class GithubOAuth
{
    /**
     * Get GitHub OAuth account for the user.
     */
    private function getAccount(User $user): OAuthAccount
    {
        $account = OAuthAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', 'github')
            ->first();

        if ($account === null) {
            throw new RuntimeException('GitHub account not connected for the user.');
        }

        return $account;
    }

    /**
     * Get GitHub OAuth access token for the user.
     *
     * Used by operations that require GitHub user authentication.
     */
    public function getAccessToken(User $user): string
    {
        $accessToken = $this->getAccount($user)->access_token;

        if (blank($accessToken)) {
            throw new RuntimeException('GitHub access token is missing for the user.');
        }

        return $accessToken;
    }

    /**
     * Get GitHub OAuth access token if available.
     *
     * Public repository operations can work without a token.
     */
    public function getOptionalAccessToken(User $user): ?string
    {
        $accessToken = $this->getAccount($user)->access_token;

        return filled($accessToken)
            ? $accessToken
            : null;
    }

    /**
     * Get the GitHub username stored during OAuth synchronization.
     */
    public function getUsername(User $user): string
    {
        $username = $this->getAccount($user)->provider_username;

        if (blank($username)) {
            throw new RuntimeException('GitHub username is missing for the user.');
        }

        return $username;
    }
}
