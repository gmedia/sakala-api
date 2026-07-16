<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('returns 401 for guest reading current user', function () {
    $this->getJson('/api/v1/auth/user')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('returns user resource for authenticated session', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum');

    $this->getJson('/api/v1/auth/user')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'name', 'email', 'avatar_url'],
        ])
        ->assertJsonPath('data.email', $user->email);
});

it('invalidates session on logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/logout', [], [
            'Origin' => config('sanctum.stateful')[0] ?? 'localhost',
        ])->assertStatus(204);
});

it('returns 401 after logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/logout', [], [
            'Origin' => config('sanctum.stateful')[0] ?? 'localhost',
        ])->assertStatus(204);

    Auth::forgetGuards();

    $this->getJson('/api/v1/auth/user')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('rejects unsafe request without CSRF token', function () {
    // Override CSRF middleware to actually validate in test environment.
    $testableCsrf = new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    };

    $this->swap(PreventRequestForgery::class, $testableCsrf);
    $this->swap(VerifyCsrfToken::class, $testableCsrf);
    $this->swap(ValidateCsrfToken::class, $testableCsrf);

    $this->postJson('/api/v1/auth/logout', [], [
        'Origin' => 'http://app.sakala.localhost:5173',
    ])->assertStatus(419)
        ->assertJsonPath('message', 'CSRF token mismatch.');
});

it('does not authenticate requests from disallowed origin', function () {
    $user = User::factory()->create();

    // Simulate session-based login without actingAs (which bypasses Sanctum origin check).
    // When origin is not in SANCTUM_STATEFUL_DOMAINS, EnsureFrontendRequestsAreStateful
    // does not activate AuthenticateSession, so the user remains a guest.
    $this->withSession([
        'login_web_'.$user->getAuthIdentifier() => $user->getKey(),
        'password_hash_web' => $user->getAuthPassword(),
    ])->getJson('/api/v1/auth/user', [
        'Origin' => 'http://evil.com',
    ])->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});
