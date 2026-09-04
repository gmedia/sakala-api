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
use Illuminate\Support\Facades\DB;

final class SyncGoogleOAuthIdentityAction
{
    public function handle(GoogleOAuthIdentityData $identity): User
    {
        return DB::transaction(function () use ($identity): User {
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

                $user = User::query()->lockForUpdate()->findOrFail($account->user_id);
                $user->update(['last_login_at' => now()]);

                return $user;
            }

            $emailAlreadyExists = User::query()
                ->where('email', $identity->email)
                ->lockForUpdate()
                ->exists();

            if ($emailAlreadyExists) {
                throw new GoogleOAuthIdentityException(GoogleOAuthFailure::EmailConflict);
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
                'provider' => OAuthProvider::Google,
                'provider_user_id' => $identity->providerUserId,
                'provider_username' => $identity->providerUsername,
                'avatar_url' => $identity->avatarUrl,
            ]);

            return $user;
        });
    }
}
