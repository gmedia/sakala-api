<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginData;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

final class LoginAction
{
    public function handle(LoginData $data): User
    {
        $guard = Auth::guard('web');

        $authenticated = $guard->attempt([
            'email' => $data->email,
            'password' => $data->password,
        ]);

        $user = $guard->user();

        if (! $authenticated || ! $user instanceof User || $user->email_verified_at === null) {
            if ($authenticated) {
                $guard->logout();
            }

            throw new AuthenticationException(guards: ['web']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user->fresh() ?? $user;
    }
}
