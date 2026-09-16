<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VerifyEmailAction
{
    public function handle(Request $request, int $userId, string $hash): bool
    {
        if (! $request->hasValidSignature()) {
            return false;
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User
            || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return false;
        }

        $verificationState = DB::transaction(function () use ($user): ?bool {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->find($user->getKey());

            if (! $lockedUser instanceof User) {
                return false;
            }

            if ($lockedUser->hasVerifiedEmail()) {
                return null;
            }

            return $lockedUser->markEmailAsVerified();
        });

        if ($verificationState === null) {
            return true;
        }

        if ($verificationState === false) {
            return false;
        }

        $verifiedUser = $user->fresh() ?? $user;
        event(new Verified($verifiedUser));

        return true;
    }
}
