<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use App\Services\GitHub\GithubInstallationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class GithubInstallationSetupController extends Controller
{
    public function __invoke(Request $request, GithubInstallationService $installations, ConsoleAuthenticationRedirect $redirect): RedirectResponse
    {
        $expectedState = $request->session()->pull('github_app_installation_state');
        $providedState = $request->query('state');
        $id = $request->integer('installation_id');
        $configuredInstallationId = $request->session()->pull('github_app_configure_installation_id');
        $validState = is_string($expectedState) && $expectedState !== '' && is_string($providedState) && $providedState !== '' && hash_equals($expectedState, $providedState);
        $validConfiguration = is_int($configuredInstallationId) && $configuredInstallationId === $id;
        abort_unless($validState || $validConfiguration, 403);
        abort_if($id < 1, 422);

        $installations->setup($request->user(), $id);

        return redirect()->away($redirect->dashboard().'?github_installation=connected');
    }
}
