<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\TestingPreventRequestForgery;

uses(LazilyRefreshDatabase::class);

test('the first-party console can bootstrap a CSRF cookie', function () {
    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN')
        ->assertHeader('access-control-allow-origin', 'http://app.sakala.localhost:5173')
        ->assertHeader('access-control-allow-credentials', 'true');
});

test('an untrusted origin cannot receive CORS permission for the CSRF cookie', function () {
    $this->withHeader('Origin', 'https://untrusted.example.test')
        ->get('/sanctum/csrf-cookie')
        ->assertHeaderMissing('access-control-allow-origin')
        ->assertHeaderMissing('access-control-allow-credentials');
});

test('only configured Console origins are treated as stateful', function () {
    config()->set('sanctum.stateful', ['app.sakala.localhost:5173']);

    $consoleRequest = Request::create(
        '/api/v1/auth/user',
        'GET',
        server: ['HTTP_ORIGIN' => 'http://app.sakala.localhost:5173'],
    );
    $untrustedRequest = Request::create(
        '/api/v1/auth/user',
        'GET',
        server: ['HTTP_ORIGIN' => 'https://untrusted.example.test'],
    );

    expect(EnsureFrontendRequestsAreStateful::fromFrontend($consoleRequest))->toBeTrue()
        ->and(EnsureFrontendRequestsAreStateful::fromFrontend($untrustedRequest))->toBeFalse();
});

test('a guest cannot retrieve the current console user', function () {
    $this->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
});

test('a verified user can log in with email and password', function () {
    $user = User::factory()->create();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.last_login_at', fn (mixed $value): bool => is_string($value))
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('token');

    $this->assertAuthenticatedAs($user, 'web');

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
});

test('successful login sets an HttpOnly session cookie instead of a bearer token', function () {
    $user = User::factory()->create();

    $response = $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertOk()
        ->assertCookie(config('session.cookie'));

    $sessionCookie = $response->getCookie((string) config('session.cookie'), false);

    expect($sessionCookie)->toBeInstanceOf(Cookie::class)
        ->and($sessionCookie?->isHttpOnly())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

test('successful login rotates the session identifier', function () {
    $user = User::factory()->create();

    $csrfResponse = $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->get('/sanctum/csrf-cookie');
    $oldSessionCookie = $csrfResponse->getCookie((string) config('session.cookie'));

    expect($oldSessionCookie)->toBeInstanceOf(Cookie::class);

    if (! $oldSessionCookie instanceof Cookie) {
        return;
    }

    $loginResponse = $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->withCookie($oldSessionCookie->getName(), $oldSessionCookie->getValue())
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertOk();
    $newSessionCookie = $loginResponse->getCookie((string) config('session.cookie'), false);

    expect($newSessionCookie)->toBeInstanceOf(Cookie::class);

    if ($newSessionCookie instanceof Cookie) {
        expect($newSessionCookie->getValue())->not->toBe($csrfResponse->getCookie((string) config('session.cookie'), false)?->getValue());
    }
});

test('invalid login credentials return a generic unauthorized response', function () {
    $user = User::factory()->create();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');

    $this->assertGuest('web');
    expect($user->fresh()->last_login_at)->toBeNull();
});

test('an unknown email returns the same generic unauthorized response', function () {
    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), [
            'email' => 'unknown@example.test',
            'password' => 'password',
        ])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.')
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('token');

    $this->assertGuest('web');
});

test('an OAuth-only user without a password cannot log in with email and password', function () {
    $user = User::factory()->create(['password' => null]);

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');

    $this->assertGuest('web');
    expect($user->fresh()->last_login_at)->toBeNull();
});

test('an unverified user cannot log in with email and password', function () {
    $user = User::factory()->unverified()->create();
    Event::fake();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');

    Event::assertNotDispatched(Login::class);
    $this->assertGuest('web');
    expect($user->fresh()->last_login_at)->toBeNull();
});

test('login enforces a dedicated rate limit by normalized email and IP', function () {
    config()->set('sakala.rate_limits.login', 1);

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->postJson(route('api.v1.auth.login'), [
            'email' => 'User@example.test',
            'password' => 'incorrect-password',
        ])
        ->assertUnauthorized();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->postJson(route('api.v1.auth.login'), [
            'email' => 'other@example.test',
            'password' => 'incorrect-password',
        ])
        ->assertUnauthorized();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->postJson(route('api.v1.auth.login'), [
            'email' => 'user@example.test',
            'password' => 'incorrect-password',
        ])
        ->assertTooManyRequests();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
        ->postJson(route('api.v1.auth.login'), [
            'email' => 'USER@example.test',
            'password' => 'incorrect-password',
        ])
        ->assertUnauthorized();
});

test('login uses the default five attempts per minute before throttling', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
            ->postJson(route('api.v1.auth.login'), [
                'email' => 'rate-limit@example.test',
                'password' => 'incorrect-password',
            ])
            ->assertUnauthorized();
    }

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), [
            'email' => 'rate-limit@example.test',
            'password' => 'incorrect-password',
        ])
        ->assertTooManyRequests();
});

test('login validates email and password input', function () {
    $this->postJson(route('api.v1.auth.login'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);

    $this->postJson(route('api.v1.auth.login'), [
        'email' => 'not-an-email',
        'password' => [],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('login rejects null and array credentials before authentication', function () {
    $this->postJson(route('api.v1.auth.login'), [
        'email' => null,
        'password' => null,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);

    $this->postJson(route('api.v1.auth.login'), [
        'email' => ['user@example.test'],
        'password' => ['password'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('an authenticated console session can retrieve the current user', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->getJson(route('api.v1.auth.user'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('token');

    expect($user->tokens()->count())->toBe(0);
});

test('a bearer token cannot retrieve the current console user', function () {
    $user = User::factory()->create();
    $token = $user->createToken('console-session-boundary')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
});

test('logout invalidates the console session', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.logout'))
        ->assertNoContent();

    $this->assertGuest('web');

    app('auth')->forgetGuards();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
});

test('a bearer token cannot log out a console session', function () {
    $user = User::factory()->create();
    $token = $user->createToken('console-session-boundary')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.auth.logout'))
        ->assertUnauthorized();
});

test('a stateful console logout request requires a CSRF token', function () {
    config()->set('sanctum.middleware.validate_csrf_token', TestingPreventRequestForgery::class);

    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.logout'))
        ->assertStatus(419)
        ->assertJsonPath('message', 'CSRF token mismatch.');
});

test('a stateful console login request requires a CSRF token', function () {
    config()->set('sanctum.middleware.validate_csrf_token', TestingPreventRequestForgery::class);

    $user = User::factory()->create();

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertStatus(419)
        ->assertJsonPath('message', 'CSRF token mismatch.');

    $this->assertGuest('web');
});

test('a stateful console can log in with its CSRF token and session cookie', function () {
    config()->set('sanctum.middleware.validate_csrf_token', TestingPreventRequestForgery::class);

    $user = User::factory()->create();
    $csrfResponse = $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->get('/sanctum/csrf-cookie');
    $csrfCookie = $csrfResponse->getCookie('XSRF-TOKEN', false);
    $sessionCookie = $csrfResponse->getCookie((string) config('session.cookie'), false);

    expect($csrfCookie)->toBeInstanceOf(Cookie::class)
        ->and($sessionCookie)->toBeInstanceOf(Cookie::class);

    if (! $csrfCookie instanceof Cookie || ! $sessionCookie instanceof Cookie) {
        return;
    }

    $this->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->withHeader('X-XSRF-TOKEN', $csrfCookie->getValue())
        ->withUnencryptedCookie($csrfCookie->getName(), $csrfCookie->getValue())
        ->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
        ->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
        ->assertOk();

    $this->assertAuthenticatedAs($user, 'web');
});
