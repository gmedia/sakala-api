<?php

declare(strict_types=1);

use App\Enums\OAuthProvider;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.github.client_id', 'github-client-id');
    config()->set('services.github.client_secret', 'github-client-secret');
    config()->set('services.github.redirect', 'http://api.sakala.localhost:8000/auth/github/callback');
    config()->set('sakala.console_url', 'http://app.sakala.localhost:5173');
});

test('the GitHub redirect uses Socialite state and only profile scopes needed for login', function () {
    $response = $this->get(route('auth.github.redirect'));

    $response->assertRedirect();

    $query = [];
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($response->headers->get('Location'))->toStartWith('https://github.com/login/oauth/authorize?')
        ->and($query['redirect_uri'])->toBe('http://api.sakala.localhost:8000/auth/github/callback')
        ->and(explode(',', (string) $query['scope']))->toContain('read:user', 'user:email')
        ->and(explode(',', (string) $query['scope']))->not->toContain('repo')
        ->and($query['state'])->toBeString()->not->toBeEmpty()
        ->and(session('state'))->toBe($query['state']);
});

test('a GitHub callback creates the user, creates its provider identity, and starts a console session', function () {
    $socialiteUser = SocialiteUser::fake([
        'id' => '84290817',
        'nickname' => 'sakala-builder',
        'name' => 'Sakala Builder',
        'email' => 'builder@example.test',
        'avatar' => 'https://avatars.example.test/builder.png',
        'token' => 'provider-token-that-must-not-be-persisted',
    ]);
    Socialite::fake('github', $socialiteUser);

    $sessionId = session()->getId();

    $this->get(route('auth.github.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    $user = User::query()->sole();

    $this->assertAuthenticatedAs($user, 'web');
    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->getJson(route('api.v1.auth.user'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    expect(session()->getId())->not->toBe($sessionId)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->last_login_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);

    $this->assertDatabaseHas('oauth_accounts', [
        'user_id' => $user->id,
        'provider' => OAuthProvider::Github->value,
        'provider_user_id' => '84290817',
        'provider_username' => 'sakala-builder',
        'avatar_url' => 'https://avatars.example.test/builder.png',
        'access_token' => null,
        'refresh_token' => null,
    ]);
});

test('a numeric GitHub provider ID is stored as a string identity', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 84290817,
        'email' => 'numeric-id@example.test',
    ]));

    $this->get(route('auth.github.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    $this->assertDatabaseHas('oauth_accounts', [
        'provider' => OAuthProvider::Github->value,
        'provider_user_id' => '84290817',
    ]);
});

test('a returning GitHub identity uses its existing user without creating duplicates', function () {
    $user = User::factory()->create(['last_login_at' => null]);
    OAuthAccount::factory()->for($user)->create([
        'provider' => OAuthProvider::Github,
        'provider_user_id' => '84290817',
        'provider_username' => 'old-username',
    ]);
    Socialite::fake('github', SocialiteUser::fake([
        'id' => '84290817',
        'nickname' => 'updated-username',
        'email' => 'different-github-email@example.test',
        'avatar' => 'https://avatars.example.test/updated.png',
    ]));

    $this->get(route('auth.github.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    $this->assertAuthenticatedAs($user, 'web');
    expect(User::query()->count())->toBe(1)
        ->and(OAuthAccount::query()->count())->toBe(1)
        ->and($user->fresh()->last_login_at)->not->toBeNull();

    $this->assertDatabaseHas('oauth_accounts', [
        'id' => $user->oauthAccounts()->sole()->id,
        'provider_username' => 'updated-username',
        'avatar_url' => 'https://avatars.example.test/updated.png',
    ]);
});

test('a GitHub email cannot silently link an existing account without its provider identity', function () {
    User::factory()->create(['email' => 'existing@example.test']);
    Socialite::fake('github', SocialiteUser::fake([
        'id' => '84290817',
        'email' => 'existing@example.test',
    ]));

    $this->get(route('auth.github.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=github_email_conflict');

    $this->assertGuest('web');
    expect(OAuthAccount::query()->count())->toBe(0);
});

test('a GitHub callback without a verified email returns a safe recovery redirect', function () {
    Socialite::fake('github', SocialiteUser::fake([
        'id' => '84290817',
        'email' => null,
    ]));

    $this->get(route('auth.github.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=github_email_unavailable');

    $this->assertGuest('web');
    expect(User::query()->count())->toBe(0);
});

test('a denied GitHub consent returns a safe recovery redirect', function () {
    $this->get(route('auth.github.callback', ['error' => 'access_denied']))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=github_access_denied');

    $this->assertGuest('web');
});

test('an invalid OAuth state returns a safe recovery redirect', function () {
    Socialite::fake('github', function (): never {
        throw new InvalidStateException;
    });

    $this->get(route('auth.github.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=github_invalid_state');

    $this->assertGuest('web');
});

test('a provider failure returns a safe recovery redirect', function () {
    Socialite::fake('github', function (): never {
        throw new RuntimeException('GitHub is unavailable');
    });

    $this->get(route('auth.github.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=github_provider_failure');

    $this->assertGuest('web');
});
