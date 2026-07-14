<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

final class RedirectToGitHubAction
{
    public function handle(): string
    {
        // Generate secure state parameter for CSRF protection
        $state = Str::random(64);

        // Store state in session for validation during callback
        Session::put('github_oauth_state', $state);

        // Generate GitHub OAuth redirect URL with proper callback route
        $redirectUrl = '/auth/github/callback';

        // Set the redirect URL for Socialite
        config(['services.github.redirect' => $redirectUrl]);

        return Socialite::driver('github')
            ->redirect()
            ->getTargetUrl();
    }
}
