<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\VerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class VerifyEmailController extends Controller
{
    public function __invoke(
        Request $request,
        string $user,
        string $hash,
        VerifyEmailAction $action,
        ConsoleAuthenticationRedirect $redirect,
    ): RedirectResponse {
        if (! $action->handle($request, (int) $user, $hash)) {
            return redirect()->away($redirect->emailVerificationError());
        }

        return redirect()->away($redirect->emailVerificationSuccess());
    }
}
