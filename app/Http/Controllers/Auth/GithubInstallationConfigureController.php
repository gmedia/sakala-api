<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GithubInstallation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class GithubInstallationConfigureController extends Controller
{
    public function __invoke(Request $request, GithubInstallation $installation): RedirectResponse
    {
        abort_unless($installation->users()->whereKey($request->user()->id)->exists(), 403);

        $request->session()->put('github_app_configure_installation_id', $installation->github_installation_id);

        if ($installation->account_type === 'Organization') {
            return redirect()->away('https://github.com/organizations/'.rawurlencode($installation->account_login)."/settings/installations/{$installation->github_installation_id}");
        }

        return redirect()->away("https://github.com/settings/installations/{$installation->github_installation_id}");
    }
}
