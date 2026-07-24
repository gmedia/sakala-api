<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

final class LogoutCurrentUserAction
{
    public function handle(Session $session): void
    {
        Auth::guard('web')->logout();

        $session->invalidate();
        $session->regenerateToken();
    }
}
