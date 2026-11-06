<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\Notification\UserNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Changes the password, or sets the first one for an OAuth-only account.
 *
 * Other sessions end on their next request: Sanctum's AuthenticateSession
 * compares the password hash stored in each session with the current one and
 * logs out the mismatch. The session making this request stays logged in,
 * because that middleware stores the new hash when the response is built.
 */
final class ChangePasswordAction
{
    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    public function handle(User $user, string $password): User
    {
        $isFirstPassword = $user->password === null;

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
        });

        $this->notifier->send(
            $user,
            NotificationCategory::AccountSecurity,
            $isFirstPassword ? 'Kata sandi dibuat' : 'Kata sandi diganti',
            'Kata sandi akun Sakala kamu baru saja diperbarui. Kalau ini bukan kamu, segera reset kata sandi.',
        );

        return $user;
    }
}
