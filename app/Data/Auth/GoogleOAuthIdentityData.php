<?php

declare(strict_types=1);

namespace App\Data\Auth;

use App\Enums\GoogleOAuthFailure;
use App\Exceptions\Auth\GoogleOAuthIdentityException;
use Laravel\Socialite\Two\User as SocialiteUser;

final readonly class GoogleOAuthIdentityData
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

        if ($providerUserId === null) {
            throw new GoogleOAuthIdentityException(GoogleOAuthFailure::ProviderFailure);
        }

        $rawProfile = $user->getRaw();
        $email = self::normalized($user->getEmail());

        if (($rawProfile['email_verified'] ?? null) !== true
            || $email === null
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GoogleOAuthIdentityException(GoogleOAuthFailure::EmailUnavailable);
        }

        $providerUsername = self::normalized($user->getNickname());

        return new self(
            providerUserId: $providerUserId,
            providerUsername: $providerUsername,
            name: self::normalized($user->getName()) ?? $providerUsername ?? 'Google user',
            email: mb_strtolower($email, 'UTF-8'),
            avatarUrl: self::normalized($user->getAvatar()),
        );
    }

    private static function normalized(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

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
