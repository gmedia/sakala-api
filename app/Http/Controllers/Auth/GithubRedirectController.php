<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GitHub\GithubAppOAuthService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class GithubRedirectController extends Controller
{
    public function __invoke(Request $request, GithubAppOAuthService $oauth): RedirectResponse
    {
        return $oauth->redirect($request);
    }
}
