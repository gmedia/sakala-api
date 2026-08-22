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
        abort_unless(hash_equals((string) $request->session()->pull('github_app_installation_state'), (string) $request->query('state')), 403);
        $id = $request->integer('installation_id');
        abort_if($id < 1, 422);

        $installations->setup($request->user(), $id);

        return redirect()->away($redirect->dashboard().'?github_installation=connected');
    }
}
