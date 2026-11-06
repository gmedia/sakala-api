<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Password;

final class SendPasswordResetLinkAction
{
    /**
     * The broker's status is ignored on purpose: unknown, throttled, and sent
     * all look the same to the caller, so no email can be confirmed this way.
     */
    public function handle(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }
}
