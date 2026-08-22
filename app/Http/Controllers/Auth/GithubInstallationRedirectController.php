<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class GithubInstallationRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $slug = config('services.github_app.slug');
        if (! is_string($slug) || $slug === '') {
            abort(503, 'GitHub App is not configured.');
        }

        $state = Str::random(64);
        $request->session()->put('github_app_installation_state', $state);

        return redirect()->away("https://github.com/apps/{$slug}/installations/new?".http_build_query(['state' => $state]));
    }
}
