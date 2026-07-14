<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\OAuthAccount;
use App\Models\User;

class AuthService
{
    public function syncUser(OAuthAccount $oauthAccount): User
    {
        // 1. Cari user berdasarkan email provider
        $user = User::where('email', $oauthAccount->provider_username)->first();

        // 2. Jika tidak ada, buat user baru
        if (! $user) {
            $user = User::create([
                'name' => $oauthAccount->provider_username,
                'email' => $oauthAccount->provider_username,
                'avatar_url' => $oauthAccount->avatar_url,
            ]);
        }

        // 3. Update token OAuth
        $oauthAccount->update([
            'user_id' => $user->id,
            'refresh_token' => $oauthAccount->refreshToken,
        ]);

        return $user;
    }
}
