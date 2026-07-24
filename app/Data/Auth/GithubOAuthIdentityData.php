<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Enums\GithubOAuthFailure;
use App\Exceptions\Auth\GithubOAuthIdentityException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

final readonly class GithubOAuthIdentityData
{
    public function __construct(
        public string $providerUserId,
        public ?string $providerUsername,
        public string $name,
        public string $email,
        public ?string $avatarUrl,
    ) {}

    public static function fromSocialiteUser(SocialiteUser $user): self
    {
        $providerUserId = self::normalizedProviderId($user->getId());
        $email = self::normalized($user->getEmail());
        $providerUsername = self::normalized($user->getNickname());

        if ($providerUserId === null) {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::ProviderFailure);
        }

        // GitHub Socialite requests user:email and returns only a primary,
        // verified email address. Never infer one from an unverified profile.
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::EmailUnavailable);
        }

        return new self(
            providerUserId: $providerUserId,
            providerUsername: $providerUsername,
            name: self::normalized($user->getName()) ?? $providerUsername ?? 'GitHub user',
            email: mb_strtolower($email),
            avatarUrl: self::normalized($user->getAvatar()),
        );
    }

    private static function normalized(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalizedProviderId(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return self::normalized((string) $value);
    }
}
