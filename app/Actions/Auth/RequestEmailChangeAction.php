<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\Auth\ConfirmEmailChangeNotification;
use App\Notifications\Auth\EmailChangeNoticeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

final class RequestEmailChangeAction
{
    /**
     * Records the pending address and mails the signed link to it. The answer
     * is the same when the address already belongs to another account, so the
     * endpoint cannot be used to learn which emails are registered; that
     * account simply receives nothing.
     */
    public function handle(User $user, string $newEmail): User
    {
        DB::transaction(function () use ($user, $newEmail): void {
            $user->update(['pending_email' => $newEmail]);

            $taken = User::query()->where('email', $newEmail)->exists();

            if ($taken) {
                return;
            }

            Notification::route('mail', $newEmail)->notify(new ConfirmEmailChangeNotification(
                name: $user->name,
                url: URL::temporarySignedRoute(
                    'email-change.confirm',
                    now()->addMinutes((int) config('auth.verification.expire', 60)),
                    ['user' => $user->id, 'hash' => sha1($newEmail)],
                ),
            ));

            Notification::route('mail', $user->email)->notify(new EmailChangeNoticeNotification(
                name: $user->name,
                newEmail: $newEmail,
                applied: false,
            ));
        });

        return $user;
    }
}
