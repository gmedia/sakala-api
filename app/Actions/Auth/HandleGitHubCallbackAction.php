<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

final class HandleGitHubCallbackAction
{
    public function __construct(
        private SyncGitHubUserAction $syncGitHubUserAction,
        private GenerateConsoleRedirectUrlAction $generateConsoleRedirectUrlAction,
    ) {}

    public function handle(string $code, string $state, Request $request): string
    {
        Session::pull('github_oauth_state');

        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (\Exception $e) {
            throw new ValidationException(
                validator: Validator::make([], []),
                response: response()->json([
                    'message' => 'GitHub authentication failed.',
                    'errors' => [
                        'provider' => ['Authentication provider error occurred.'],
                    ],
                ], 422)
            );
        }

        $user = $this->syncGitHubUserAction->handle($githubUser);
        Auth::login($user);

        $request->session()->regenerate();

        return $this->generateConsoleRedirectUrlAction->handle();
    }
}
