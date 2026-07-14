<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

// Define a valid 64-character state string for testing (exactly 64 characters)
define('TEST_VALID_STATE', '1234567890123456789012345678901234567890123456789012345678901234');

beforeEach(function () {
    // Ensure a completely clean session for each test
    Session::flush();

    // Explicitly define routes within the test environment
    Route::get('/auth/github/redirect', [AuthController::class, 'redirectToGitHub'])
        ->name('auth.github.redirect');

    Route::get('/auth/github/callback', [AuthController::class, 'handleGitHubCallback'])
        ->name('auth.github.callback');

    // Set up session with the valid 64-character state for CSRF validation
    Session::put('github_oauth_state', TEST_VALID_STATE);
});

it('redirects to GitHub OAuth page', function () {
    // Mock the Socialite redirect
    $mockRedirect = Mockery::mock('Illuminate\Http\RedirectResponse');
    $mockRedirect->shouldReceive('getTargetUrl')->andReturn('https://github.com/login/oauth/authorize?client_id=test&redirect_uri=test&state='.TEST_VALID_STATE);

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('redirect')->andReturn($mockRedirect);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    // Redirect test is web redirect, so get() with 302 is correct
    $response = $this->get('/auth/github/redirect');

    $response->assertStatus(302);
    $response->assertRedirectContains('github.com/login/oauth/authorize');
});

it('handles GitHub callback successfully for new user', function () {
    // Create mock Socialite user
    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('12345');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn('github@example.com');
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/12345');
    $mockUser->shouldReceive('token')->andReturn('fake-access-token');
    $mockUser->shouldReceive('refreshToken')->andReturn(null);
    $mockUser->shouldReceive('expiresIn')->andReturn(null);

    // Mock the Socialite user method on the driver
    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    // Use getJson to ensure we expect JSON response (200) instead of redirect (302) on web route
    $callbackUrl = '/auth/github/callback?code=test-code&state='.TEST_VALID_STATE;
    $response = $this->getJson($callbackUrl);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            'token',
            'redirect_url',
        ],
    ]);

    // Assert user was created using Pest's assertDatabaseHas
    assertDatabaseHas('users', ['email' => 'github@example.com']);
    assertDatabaseHas('oauth_accounts', [
        'provider' => 'github',
        'provider_user_id' => '12345',
    ]);
});

it('handles GitHub callback successfully for existing user', function () {
    // Create existing user
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    // Create mock Socialite user with same email
    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('12345');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn('existing@example.com');
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/12345');
    $mockUser->shouldReceive('token')->andReturn('fake-access-token');
    $mockUser->shouldReceive('refreshToken')->andReturn(null);
    $mockUser->shouldReceive('expiresIn')->andReturn(null);

    // Mock the Socialite user method on the driver
    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    // Use getJson to ensure we expect JSON response (200) instead of redirect (302) on web route
    $callbackUrl = '/auth/github/callback?code=test-code&state='.TEST_VALID_STATE;
    $response = $this->getJson($callbackUrl);

    $response->assertStatus(200);

    // Assert existing user was updated, not duplicated
    expect(User::where('email', 'existing@example.com')->count())->toBe(1);

    // Assert OAuth account was created with user_id
    assertDatabaseHas('oauth_accounts', [
        'user_id' => $existingUser->id,
        'provider' => 'github',
        'provider_user_id' => '12345',
    ]);
});

it('rejects callback with invalid state parameter (too short)', function () {
    // Ensure Socialite driver is not called in this test scenario
    Socialite::shouldReceive('driver')->with('github')->never();

    $response = $this->getJson('/auth/github/callback?code=test-code&state=invalid-state');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['state']);
});

it('rejects callback with mismatched state parameter (different 64 chars)', function () {
    // Ensure Socialite driver is not called in this test scenario
    Socialite::shouldReceive('driver')->with('github')->never();

    // Session state is TEST_VALID_STATE (64 chars).
    // The request state is a different 64-character string.
    $mismatchedState = 'abcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcd'; // Exactly 64 characters

    $callbackUrl = '/auth/github/callback?code=test-code&state='.$mismatchedState;
    $response = $this->getJson($callbackUrl);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['state']);
});
