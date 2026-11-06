<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConfirmEmailChangeAction;
use App\Http\Controllers\Controller;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class ConfirmEmailChangeController extends Controller
{
    public function __invoke(
        Request $request,
        string $user,
        string $hash,
        ConfirmEmailChangeAction $action,
        ConsoleAuthenticationRedirect $redirect,
    ): RedirectResponse {
        return redirect()->away(
            $action->handle($request, (int) $user, $hash)
                ? $redirect->emailChangeSuccess()
                : $redirect->emailChangeError(),
        );
    }
}
