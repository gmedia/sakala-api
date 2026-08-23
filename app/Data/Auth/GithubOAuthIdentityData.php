<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Enums\GithubOAuthFailure;
use App\Exceptions\Auth\GithubOAuthIdentityException;

final readonly class GithubOAuthIdentityData
{
    public function __construct(
        public string $providerUserId,
        public ?string $providerUsername,
        public string $name,
        public string $email,
        public ?string $avatarUrl,
        public string $accessToken,
        public ?string $refreshToken,
        public ?int $expiresIn,
    ) {}

    /** @param array<string, mixed> $profile */
    public static function fromGithubProfile(array $profile, string $email, string $accessToken, ?string $refreshToken, ?int $expiresIn): self
    {
        $providerUserId = self::normalizedProviderId($profile['id'] ?? null);
        $email = self::normalized($email);
        $providerUsername = self::normalized(is_string($profile['login'] ?? null) ? $profile['login'] : null);

        if ($providerUserId === null) {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::ProviderFailure);
        }

        // GitHub App email data must be primary and verified. Never infer an
        // address from an unverified profile.
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GithubOAuthIdentityException(GithubOAuthFailure::EmailUnavailable);
        }

        return new self(
            providerUserId: $providerUserId,
            providerUsername: $providerUsername,
            name: self::normalized(is_string($profile['name'] ?? null) ? $profile['name'] : null) ?? $providerUsername ?? 'GitHub user',
            email: mb_strtolower($email),
            avatarUrl: self::normalized(is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null),
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresIn: $expiresIn,
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
