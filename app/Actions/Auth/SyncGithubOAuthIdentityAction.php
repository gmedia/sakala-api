<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\GithubOAuthIdentityData;
use App\Enums\GithubOAuthFailure;
use App\Enums\OAuthProvider;
use App\Enums\UserRole;
use App\Exceptions\Auth\GithubOAuthIdentityException;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SyncGithubOAuthIdentityAction
{
    private const MAX_USERNAME_ATTEMPTS = 10;

    private function isUsernameCollision(
        UniqueConstraintViolationException $exception,
    ): bool {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'users_username_unique')
            || str_contains($message, 'users.username');
    }

    public function __construct(
        private CreateUserWithUniqueUsernameAction $createUserWithUniqueUsernameAction,
    ) {}

    public function handle(GithubOAuthIdentityData $identity): User
    {
        $usernameSource = $identity->providerUsername ?? $identity->name;

        for ($attempt = 0; $attempt <= self::MAX_USERNAME_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $identity,
                    $usernameSource,
                    $attempt,
                ): User {
                    $account = OAuthAccount::query()
                        ->where('provider', OAuthProvider::Github)
                        ->where('provider_user_id', $identity->providerUserId)
                        ->lockForUpdate()
                        ->first();

                    if ($account instanceof OAuthAccount) {
                        $account->update([
                            'provider_username' => $identity->providerUsername,
                            'avatar_url' => $identity->avatarUrl,
                            'access_token' => $identity->accessToken,
                            'refresh_token' => $identity->refreshToken,
                            'token_expires_at' => $identity->expiresIn !== null
                                ? now()->addSeconds($identity->expiresIn)
                                : null,
                        ]);

                        $user = User::query()
                            ->lockForUpdate()
                            ->findOrFail($account->user_id);

                        $user->update([
                            'last_login_at' => now(),
                        ]);

                        return $user;
                    }

                    $emailAlreadyExists = User::query()
                        ->where('email', $identity->email)
                        ->lockForUpdate()
                        ->exists();

                    if ($emailAlreadyExists) {
                        throw new GithubOAuthIdentityException(
                            GithubOAuthFailure::EmailConflict,
                        );
                    }

                    $user = $this->createUserWithUniqueUsernameAction->handle(
                        attributes: [
                            'name' => $identity->name,
                            'email' => $identity->email,
                            'role' => UserRole::User,
                            'avatar_url' => $identity->avatarUrl,
                            'last_login_at' => now(),
                        ],
                        usernameSource: $usernameSource,
                        attempt: $attempt,
                    );

                    $user->forceFill([
                        'email_verified_at' => now(),
                    ])->save();

                    OAuthAccount::query()->create([
                        'user_id' => $user->id,
                        'provider' => OAuthProvider::Github,
                        'provider_user_id' => $identity->providerUserId,
                        'provider_username' => $identity->providerUsername,
                        'avatar_url' => $identity->avatarUrl,
                        'access_token' => $identity->accessToken,
                        'refresh_token' => $identity->refreshToken,
                        'token_expires_at' => $identity->expiresIn !== null
                            ? now()->addSeconds($identity->expiresIn)
                            : null,
                    ]);

                    return $user;
                });

            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isUsernameCollision($exception)) {
                    throw $exception;
                }

                if ($attempt === self::MAX_USERNAME_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to create user.');
    }
}
