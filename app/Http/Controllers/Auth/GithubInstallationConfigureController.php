<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\GithubInstallationFailure;
use App\Http\Controllers\Controller;
use App\Models\GithubInstallation;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class GithubInstallationConfigureController extends Controller
{
    public function __invoke(Request $request, GithubInstallation $installation, ConsoleAuthenticationRedirect $redirect): RedirectResponse
    {
        if (! $installation->users()->whereKey($request->user()->id)->exists()) {
            return redirect()->away($redirect->githubInstallationError(GithubInstallationFailure::AccessDenied));
        }

        $request->session()->put('github_app_configure_installation_id', $installation->github_installation_id);

        if ($installation->account_type === 'Organization') {
            return redirect()->away('https://github.com/organizations/'.rawurlencode($installation->account_login)."/settings/installations/{$installation->github_installation_id}");
        }

        return redirect()->away("https://github.com/settings/installations/{$installation->github_installation_id}");
    }
}
