<?php

declare(strict_types=1);

use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.github_app.client_id', 'github-app-client-id');
    config()->set('services.github_app.client_secret', 'github-app-client-secret');
    config()->set('services.github_app.redirect', 'http://api.sakala.localhost:8000/auth/github/callback');
    config()->set('sakala.console_url', 'http://app.sakala.localhost:5173');
});

function fakeGithubAppIdentity(): void
{
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'ghu_test_token', 'refresh_token' => 'ghr_test_token', 'expires_in' => 28800,
        ]),
        'https://api.github.com/user' => Http::response([
            'id' => 84290817, 'login' => 'sakala-builder', 'name' => 'Sakala Builder', 'avatar_url' => 'https://avatars.example.test/builder.png',
        ]),
        'https://api.github.com/user/emails' => Http::response([
            ['email' => 'builder@example.test', 'primary' => true, 'verified' => true],
        ]),
    ]);
}

test('GitHub App redirect uses state and does not request OAuth scopes', function (): void {
    $response = $this->get(route('auth.github.redirect'));
    $query = [];
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    $response->assertRedirect();
    expect($query['client_id'])->toBe('github-app-client-id')
        ->and($query)->not->toHaveKey('scope')
        ->and($query['state'])->toBeString()->not->toBeEmpty()
        ->and(session('github_app_oauth_state'))->toBe($query['state']);
});

test('GitHub App callback creates a user and starts a console session', function (): void {
    fakeGithubAppIdentity();
    $state = 'state-for-test';

    $this->withSession(['github_app_oauth_state' => $state])
        ->get(route('auth.github.callback', ['code' => 'oauth-code', 'state' => $state]))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user, 'web');
    $account = OAuthAccount::query()->sole();
    expect($account->access_token)->toBe('ghu_test_token')
        ->and($account->refresh_token)->toBe('ghr_test_token')
        ->and($account->token_expires_at)->not->toBeNull();
});

test('GitHub App callback rejects invalid state', function (): void {
    $this->withSession(['github_app_oauth_state' => 'expected'])
        ->get(route('auth.github.callback', ['code' => 'oauth-code', 'state' => 'wrong']))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=github_invalid_state');
});

test('GitHub App callback requires a verified primary email', function (): void {
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_test_token']),
        'https://api.github.com/user' => Http::response(['id' => 1, 'login' => 'missing-email']),
        'https://api.github.com/user/emails' => Http::response([]),
    ]);

    $this->withSession(['github_app_oauth_state' => 'state'])
        ->get(route('auth.github.callback', ['code' => 'oauth-code', 'state' => 'state']))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=github_email_unavailable');
});
