<?php

declare(strict_types=1);

use App\Enums\OAuthProvider;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Cookie;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'http://api.sakala.localhost:8000/auth/google/callback');
    config()->set('sakala.console_url', 'http://app.sakala.localhost:5173');
});

function fakeGoogleUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake(array_merge([
        'id' => 'google-user-123',
        'nickname' => null,
        'name' => 'Google User',
        'email' => 'google.user@example.test',
        'avatar' => 'https://lh3.googleusercontent.com/google-user.png',
        'email_verified' => true,
        'token' => 'google-access-token',
        'refreshToken' => 'google-refresh-token',
        'expiresIn' => 3600,
    ], $attributes));
}

test('Google redirect uses state and the default login scopes', function (): void {
    $response = $this->get(route('auth.google.redirect'));
    $query = [];
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    $response->assertRedirect();

    expect($response->headers->get('Location'))->toStartWith('https://accounts.google.com/o/oauth2/auth')
        ->and($query['client_id'])->toBe('google-client-id')
        ->and($query['redirect_uri'])->toBe('http://api.sakala.localhost:8000/auth/google/callback')
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toContain('openid')
        ->and($query['scope'])->toContain('profile')
        ->and($query['scope'])->toContain('email')
        ->and($query['state'])->toBeString()->not->toBeEmpty()
        ->and(session('state'))->toBe($query['state']);
});

test('Google callback creates a verified user and starts an HTTP-only console session', function (): void {
    Socialite::fake('google', fakeGoogleUser());

    $response = $this->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    $user = User::query()->sole();
    $account = OAuthAccount::query()->sole();

    $this->assertAuthenticatedAs($user, 'web');
    $responseCookie = $response->getCookie((string) config('session.cookie'), false);

    expect($user->email)->toBe('google.user@example.test')
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->last_login_at)->not->toBeNull()
        ->and($account->provider)->toBe(OAuthProvider::Google)
        ->and($account->provider_user_id)->toBe('google-user-123')
        ->and($account->access_token)->toBeNull()
        ->and($account->refresh_token)->toBeNull()
        ->and($account->token_expires_at)->toBeNull()
        ->and($responseCookie)->toBeInstanceOf(Cookie::class);

    if ($responseCookie instanceof Cookie) {
        expect($responseCookie->isHttpOnly())->toBeTrue();
    }

    $this->getJson(route('api.v1.auth.user'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'google.user@example.test')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('token');

    expect($user->tokens()->count())->toBe(0);
});

test('successful Google callback rotates the session identifier', function (): void {
    $redirectResponse = $this->get(route('auth.google.redirect'));
    $oldSessionCookie = $redirectResponse->getCookie((string) config('session.cookie'));

    expect($oldSessionCookie)->toBeInstanceOf(Cookie::class);

    if (! $oldSessionCookie instanceof Cookie) {
        return;
    }

    Socialite::fake('google', fakeGoogleUser());

    $callbackResponse = $this->withCookie($oldSessionCookie->getName(), $oldSessionCookie->getValue())
        ->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard');
    $newSessionCookie = $callbackResponse->getCookie((string) config('session.cookie'), false);

    expect($newSessionCookie)->toBeInstanceOf(Cookie::class);

    if ($newSessionCookie instanceof Cookie) {
        expect($newSessionCookie->getValue())->not->toBe($oldSessionCookie->getValue());
    }
});

test('a returning Google identity reuses its account without creating duplicates', function (): void {
    $user = User::factory()->create(['last_login_at' => null]);
    OAuthAccount::factory()->for($user)->create([
        'provider' => OAuthProvider::Google,
        'provider_user_id' => 'google-user-123',
        'provider_username' => 'old-google-name',
        'avatar_url' => 'https://example.test/old-avatar.png',
    ]);
    Socialite::fake('google', fakeGoogleUser([
        'name' => 'Updated Google Name',
        'avatar' => 'https://example.test/new-avatar.png',
    ]));

    $this->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    $this->assertAuthenticatedAs($user, 'web');
    $account = $user->oauthAccounts()->sole();

    expect(User::query()->count())->toBe(1)
        ->and(OAuthAccount::query()->count())->toBe(1)
        ->and($account->provider_username)->toBeNull()
        ->and($account->avatar_url)->toBe('https://example.test/new-avatar.png')
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

test('a Google email cannot silently link an existing account without its provider identity', function (): void {
    User::factory()->create(['email' => 'google.user@example.test']);
    Socialite::fake('google', fakeGoogleUser());

    $this->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=google_email_conflict');

    $this->assertGuest('web');

    expect(User::query()->count())->toBe(1)
        ->and(OAuthAccount::query()->count())->toBe(0);
});

test('Google callback normalizes the verified email address', function (): void {
    Socialite::fake('google', fakeGoogleUser(['email' => '  GOOGLE.USER@Example.TEST  ']));

    $this->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    expect(User::query()->sole()->email)->toBe('google.user@example.test');
});

test('Google callback rejects an unverified or unavailable email', function (SocialiteUser $googleUser): void {
    Socialite::fake('google', $googleUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=google_email_unavailable');

    $this->assertGuest('web');

    expect(User::query()->count())->toBe(0)
        ->and(OAuthAccount::query()->count())->toBe(0);
})->with([
    'email is unverified' => fn (): SocialiteUser => fakeGoogleUser(['email_verified' => false]),
    'email verification is missing' => function (): SocialiteUser {
        $googleUser = fakeGoogleUser();
        $rawProfile = $googleUser->getRaw();
        unset($rawProfile['email_verified']);
        $googleUser->setRaw($rawProfile);

        return $googleUser;
    },
    'email is missing' => fn (): SocialiteUser => fakeGoogleUser(['email' => null]),
    'email is invalid' => fn (): SocialiteUser => fakeGoogleUser(['email' => 'not-an-email']),
]);

test('Google callback rejects an invalid provider identity', function (): void {
    Socialite::fake('google', fakeGoogleUser(['id' => null]));

    $this->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=google_provider_failure');

    $this->assertGuest('web');
    expect(User::query()->count())->toBe(0);
});

test('a denied Google consent returns a safe recovery redirect', function (): void {
    $this->withSession(['state' => 'google-oauth-state'])
        ->get(route('auth.google.callback', [
            'error' => 'access_denied',
            'state' => 'google-oauth-state',
            'error_description' => 'private provider details',
        ]))->assertRedirect('http://app.sakala.localhost:5173/login?error=google_access_denied');

    $this->assertGuest('web');
});

test('a Google provider error requires a valid OAuth state', function (array $query): void {
    $this->withSession(['state' => 'expected-google-oauth-state'])
        ->get(route('auth.google.callback', $query))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=google_invalid_state');

    $this->assertGuest('web');
})->with([
    'state is missing' => [['error' => 'access_denied']],
    'state is invalid' => [['error' => 'access_denied', 'state' => 'wrong-google-oauth-state']],
]);

test('Google callback rejects an invalid OAuth state', function (): void {
    Socialite::fake('google', function (): never {
        throw new InvalidStateException;
    });

    $this->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=google_invalid_state');

    $this->assertGuest('web');
});

test('Google callback hides unexpected provider failures', function (): void {
    Socialite::fake('google', function (): never {
        throw new RuntimeException('private provider details');
    });

    $this->get(route('auth.google.callback'))
        ->assertRedirect('http://app.sakala.localhost:5173/login?error=google_provider_failure');

    $this->assertGuest('web');
});
