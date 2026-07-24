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
use Illuminate\Support\Facades\DB;

final class SyncGithubOAuthIdentityAction
{
    public function handle(GithubOAuthIdentityData $identity): User
    {
        return DB::transaction(function () use ($identity): User {
            $account = OAuthAccount::query()
                ->where('provider', OAuthProvider::Github)
                ->where('provider_user_id', $identity->providerUserId)
                ->lockForUpdate()
                ->first();

            if ($account instanceof OAuthAccount) {
                $account->update([
                    'provider_username' => $identity->providerUsername,
                    'avatar_url' => $identity->avatarUrl,
                ]);

                $user = User::query()->lockForUpdate()->findOrFail($account->user_id);
                $user->update(['last_login_at' => now()]);

                return $user;
            }

            $emailAlreadyExists = User::query()
                ->where('email', $identity->email)
                ->lockForUpdate()
                ->exists();

            if ($emailAlreadyExists) {
                throw new GithubOAuthIdentityException(GithubOAuthFailure::EmailConflict);
            }

            $user = User::query()->create([
                'name' => $identity->name,
                'email' => $identity->email,
                'role' => UserRole::User,
                'avatar_url' => $identity->avatarUrl,
                'last_login_at' => now(),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            OAuthAccount::query()->create([
                'user_id' => $user->id,
                'provider' => OAuthProvider::Github,
                'provider_user_id' => $identity->providerUserId,
                'provider_username' => $identity->providerUsername,
                'avatar_url' => $identity->avatarUrl,
            ]);

            return $user;
        });
    }
}
