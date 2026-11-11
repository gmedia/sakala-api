<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\PasswordResetData;
use App\Enums\NotificationCategory;
use App\Exceptions\Auth\InvalidPasswordResetTokenException;
use App\Models\User;
use App\Services\Notification\UserNotifier;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class ResetPasswordAction
{
    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    public function handle(PasswordResetData $data): void
    {
        $status = Password::broker()->reset(
            [
                'email' => $data->email,
                'token' => $data->token,
                'password' => $data->password,
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ]);

                // Opening a link sent to this mailbox proves it belongs to the
                // caller, so a stuck unverified account can log in afterwards.
                if ($user->email_verified_at === null) {
                    $user->email_verified_at = now();
                }

                $user->save();
                $user->tokens()->delete();

                event(new PasswordReset($user));

                $this->notifier->send(
                    $user,
                    NotificationCategory::AccountSecurity,
                    'Kata sandi direset',
                    'Kata sandi akun Sakala kamu baru saja direset. Kalau ini bukan kamu, segera hubungi admin Sakala.',
                );
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw new InvalidPasswordResetTokenException;
        }
    }
}
