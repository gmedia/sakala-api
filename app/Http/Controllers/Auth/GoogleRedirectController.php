<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class GoogleRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }
}
