<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginData;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

final class LoginAction
{
    public function handle(LoginData $data): User
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        $authenticated = $guard->attemptWhen(
            [
                'email' => $data->email,
                'password' => $data->password,
            ],
            static fn (User $user): bool => $user->email_verified_at !== null,
        );

        if (! $authenticated) {
            throw new AuthenticationException(guards: ['web']);
        }

        $user = $guard->user();

        if (! $user instanceof User) {
            throw new AuthenticationException(guards: ['web']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user->fresh() ?? $user;
    }
}
