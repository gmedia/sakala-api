<?php

declare(strict_types=1);

use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    Session::flush();
});

it('redirects to GitHub OAuth page', function () {
    $mockRedirect = Mockery::mock('Illuminate\Http\RedirectResponse');
    $mockRedirect->shouldReceive('getTargetUrl')->andReturn('https://github.com/login/oauth/authorize?client_id=test&redirect_uri=test&state='.'test-state-12345');

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('redirect')->andReturn($mockRedirect);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $response = $this->get('/auth/github/redirect');

    $response->assertStatus(302);
    $response->assertRedirectContains('github.com/login/oauth/authorize');
});

it('handles GitHub callback successfully for new user', function () {
    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('12345');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn('github@example.com');
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/12345');

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $callbackUrl = '/auth/github/callback?code=test-code&state='.'test-state-12345';
    $response = $this->get($callbackUrl);

    $response->assertStatus(302);
    $response->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    assertDatabaseHas('users', ['email' => 'github@example.com']);
    assertDatabaseHas('oauth_accounts', [
        'provider' => 'github',
        'provider_user_id' => '12345',
    ]);
});

it('handles GitHub callback successfully for existing identity (OAuthAccount)', function () {
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
    ]);
    $existingOAuthAccount = OAuthAccount::factory()->create([
        'user_id' => $existingUser->id,
        'provider' => 'github',
        'provider_user_id' => '12345',
    ]);

    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('12345');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn('existing@example.com');
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/12345');

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $callbackUrl = '/auth/github/callback?code=test-code&state='.'test-state-12345';
    $response = $this->get($callbackUrl);

    $response->assertStatus(302);
    $response->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
    assertDatabaseHas('oauth_accounts', [
        'id' => $existingOAuthAccount->id,
        'user_id' => $existingUser->id,
        'provider' => 'github',
        'provider_user_id' => '12345',
    ]);
});

it('handles GitHub callback successfully for existing user (email fallback)', function () {
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('99999');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn('existing@example.com');
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/99999');

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $callbackUrl = '/auth/github/callback?code=test-code&state='.'test-state-12345';
    $response = $this->get($callbackUrl);

    $response->assertStatus(302);
    $response->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
    assertDatabaseHas('oauth_accounts', [
        'user_id' => $existingUser->id,
        'provider' => 'github',
        'provider_user_id' => '99999',
    ]);
});

it('rejects callback with missing code (denied consent)', function () {
    Socialite::shouldReceive('driver')->with('github')->never();

    $response = $this->get('/auth/github/callback?state='.'test-state-12345');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['code']);
});

it('rejects callback with private or missing email', function () {
    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('12345');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn(null);
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/12345');

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $callbackUrl = '/auth/github/callback?code=test-code&state='.'test-state-12345';
    $response = $this->get($callbackUrl);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email']);
});

it('rejects callback with provider failure', function () {
    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andThrow(new Exception('GitHub error'));

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $callbackUrl = '/auth/github/callback?code=test-code&state='.'test-state-12345';
    $response = $this->get($callbackUrl);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['provider']);
});

it('rejects callback with invalid state (Socialite rejects)', function () {
    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andThrow(new InvalidStateException('Invalid state'));

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $response = $this->get('/auth/github/callback?code=test-code&state=invalid-state');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['provider']);
});

it('rejects callback with mismatched state (Socialite rejects)', function () {
    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andThrow(new InvalidStateException('State mismatch'));

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $mismatchedState = 'abcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcd';
    $callbackUrl = '/auth/github/callback?code=test-code&state='.$mismatchedState;
    $response = $this->get($callbackUrl);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['provider']);
});

it('regenerates session after successful login', function () {
    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('12345');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn('github@example.com');
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/12345');

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    // Get initial session ID
    $this->get('/');
    $oldSessionId = Session::getId();

    // Perform login
    $callbackUrl = '/auth/github/callback?code=test-code&state='.'test-state-12345';
    $this->get($callbackUrl);

    // Session ID should have changed
    $newSessionId = Session::getId();
    expect($newSessionId)->not->toBe($oldSessionId);
});

it('does not return token in callback response', function () {
    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('12345');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn('github@example.com');
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/12345');

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $callbackUrl = '/auth/github/callback?code=test-code&state='.'test-state-12345';
    $response = $this->get($callbackUrl);

    // Response should be redirect, not JSON with token
    $response->assertStatus(302);
    $response->assertRedirect('http://app.sakala.localhost:5173/dashboard');

    // Ensure no token in response body
    $response->assertDontSee('token');
    $response->assertDontSee('access_token');
    $response->assertDontSee('personal_access_token');
});

it('redirects only to allowed console origins', function () {
    $mockUser = Mockery::mock(ProviderUser::class);
    $mockUser->shouldReceive('getId')->andReturn('12345');
    $mockUser->shouldReceive('getNickname')->andReturn('githubuser');
    $mockUser->shouldReceive('getName')->andReturn('GitHub User');
    $mockUser->shouldReceive('getEmail')->andReturn('github@example.com');
    $mockUser->shouldReceive('getAvatar')->andReturn('https://avatars.githubusercontent.com/u/12345');

    $mockDriver = Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
    $mockDriver->shouldReceive('user')->andReturn($mockUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($mockDriver);

    $callbackUrl = '/auth/github/callback?code=test-code&state='.'test-state-12345';
    $response = $this->get($callbackUrl);

    $response->assertStatus(302);

    // Verify redirect is to allowed origin
    $redirectUrl = $response->headers->get('Location');
    expect($redirectUrl)->toContain('http://app.sakala.localhost:5173');
    expect($redirectUrl)->not->toContain('http://evil.com');
});

it('does not expose provider token in user resource', function () {
    // Create user with OAuth account that has tokens
    $user = User::factory()->create([
        'email' => 'github@example.com',
    ]);
    OAuthAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_user_id' => '12345',
        'access_token' => 'ghp_secret_token_12345',
        'refresh_token' => 'ghr_secret_refresh_12345',
    ]);

    // Login as user
    $this->actingAs($user);

    // Get current user
    $response = $this->getJson('/api/v1/auth/user');

    $response->assertStatus(200);
    $response->assertJsonMissing(['access_token', 'refresh_token', 'token']);
    $response->assertJsonMissingPath('oauth_accounts');
});
