<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\GoogleOAuthIdentityData;
use App\Enums\GoogleOAuthFailure;
use App\Enums\OAuthProvider;
use App\Enums\UserRole;
use App\Exceptions\Auth\GoogleOAuthIdentityException;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SyncGoogleOAuthIdentityAction
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

    public function handle(GoogleOAuthIdentityData $identity): User
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
                        ->where('provider', OAuthProvider::Google)
                        ->where('provider_user_id', $identity->providerUserId)
                        ->lockForUpdate()
                        ->first();

                    if ($account instanceof OAuthAccount) {
                        $account->update([
                            'provider_username' => $identity->providerUsername,
                            'avatar_url' => $identity->avatarUrl,
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
                        throw new GoogleOAuthIdentityException(
                            GoogleOAuthFailure::EmailConflict,
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
                        'provider' => OAuthProvider::Google,
                        'provider_user_id' => $identity->providerUserId,
                        'provider_username' => $identity->providerUsername,
                        'avatar_url' => $identity->avatarUrl,
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
