<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Laravel\Socialite\Facades\Socialite;

final class RedirectToGitHubAction
{
    public function handle(): string
    {
        // Generate GitHub OAuth redirect URL with proper callback route
        $redirectUrl = '/auth/github/callback';

        // Set the redirect URL for Socialite
        config(['services.github.redirect' => $redirectUrl]);

        // Socialite handles CSRF state internally via session
        return Socialite::driver('github')
            ->redirect()
            ->getTargetUrl();
    }
}
