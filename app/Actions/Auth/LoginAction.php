<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginData;
use App\Exceptions\Auth\EmailNotVerifiedException;
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

        // The guard only runs this callback after the password matched, so
        // the distinct error is limited to callers who already know it and
        // cannot be used to probe which emails are registered.
        $emailUnverified = false;

        $authenticated = $guard->attemptWhen(
            [
                'email' => $data->email,
                'password' => $data->password,
            ],
            static function (User $user) use (&$emailUnverified): bool {
                $emailUnverified = $user->email_verified_at === null;

                return ! $emailUnverified;
            },
        );

        if ($emailUnverified) {
            throw new EmailNotVerifiedException;
        }

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
