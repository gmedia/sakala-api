<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\Auth\EmailChangeNoticeNotification;
use App\Services\Notification\UserNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class ConfirmEmailChangeAction
{
    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    /**
     * Applies the pending email when the signed link matches it. A newer
     * request replaces `pending_email`, which is what kills older links; a
     * used link finds nothing pending and also fails.
     */
    public function handle(Request $request, int $userId, string $hash): bool
    {
        if (! $request->hasValidSignature()) {
            return false;
        }

        try {
            $applied = DB::transaction(function () use ($userId, $hash): ?array {
                $user = User::query()->lockForUpdate()->find($userId);

                if (! $user instanceof User
                    || $user->pending_email === null
                    || ! hash_equals(sha1($user->pending_email), $hash)) {
                    return null;
                }

                $oldEmail = $user->email;
                $newEmail = $user->pending_email;

                $user->forceFill([
                    'email' => $newEmail,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                ])->save();

                Notification::route('mail', $oldEmail)->notify(new EmailChangeNoticeNotification(
                    name: $user->name,
                    newEmail: $newEmail,
                    applied: true,
                ));

                $this->notifier->send(
                    $user,
                    NotificationCategory::AccountSecurity,
                    'Email diganti',
                    "Email akun Sakala kamu sekarang {$newEmail}. Kalau ini bukan kamu, segera hubungi admin Sakala.",
                );

                return ['email' => $newEmail];
            });
        } catch (UniqueConstraintViolationException) {
            // Another account took the address after the link was sent.
            return false;
        }

        return $applied !== null;
    }
}
