<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\ResendVerificationNotificationData;
use App\Models\User;

final class ResendVerificationNotificationAction
{
    public function handle(ResendVerificationNotificationData $data): void
    {
        $user = User::query()
            ->where('email', $data->email)
            ->whereNull('email_verified_at')
            ->first();

        if ($user instanceof User) {
            $user->sendEmailVerificationNotification();
        }
    }
}
