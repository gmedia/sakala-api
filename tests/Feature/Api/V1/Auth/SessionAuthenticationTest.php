<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
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
