<?php

declare(strict_types=1);

use App\Enums\GithubInstallationStatus;
use App\Models\GithubInstallation;
use App\Models\OAuthAccount;
use App\Models\User;
use App\Services\GitHub\GithubInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('user can list only their GitHub App installations', function (): void {
    $user = User::factory()->create();
    $installation = GithubInstallation::query()->create([
        'github_installation_id' => 100,
        'account_id' => 10,
        'account_login' => 'sakala',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    $otherInstallation = GithubInstallation::query()->create([
        'github_installation_id' => 200,
        'account_id' => 20,
        'account_login' => 'other',
        'account_type' => 'User',
        'repository_selection' => 'all',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    $installation->users()->attach($user, ['last_verified_at' => now()]);
    $otherInstallation->users()->attach(User::factory()->create(), ['last_verified_at' => now()]);

    $this->actingAs($user, 'web')->getJson(route('api.v1.app.github.installations.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.account_login', 'sakala')
        ->assertJsonMissingPath('data.0.github_installation_id');
});

test('multiple Sakala users can verify the same GitHub installation without changing its ownership', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    OAuthAccount::factory()->for($firstUser)->create(['access_token' => 'first-user-token']);
    OAuthAccount::factory()->for($secondUser)->create(['access_token' => 'second-user-token']);
    Http::fake([
        'https://api.github.com/user/installations*' => Http::response([
            'total_count' => 1,
            'installations' => [[
                'id' => 100,
                'account' => ['id' => 10, 'login' => 'sakala', 'type' => 'Organization'],
                'repository_selection' => 'selected',
                'permissions' => ['contents' => 'read'],
            ]],
        ]),
    ]);

    $service = app(GithubInstallationService::class);
    $firstInstallation = $service->setup($firstUser, 100);
    $secondInstallation = $service->setup($secondUser, 100);

    expect($secondInstallation->id)->toBe($firstInstallation->id)
        ->and($firstInstallation->fresh()->users()->pluck('users.id')->all())
        ->toContain($firstUser->id, $secondUser->id);
});

test('installation setup finds an installation on a later GitHub page', function (): void {
    $user = User::factory()->create();
    OAuthAccount::factory()->for($user)->create(['access_token' => 'user-access-token']);
    Http::fake([
        'https://api.github.com/user/installations?page=1&per_page=100' => Http::response([
            'total_count' => 101,
            'installations' => array_fill(0, 100, [
                'id' => 1,
                'account' => ['id' => 1, 'login' => 'other', 'type' => 'User'],
                'repository_selection' => 'selected',
                'permissions' => [],
            ]),
        ]),
        'https://api.github.com/user/installations?page=2&per_page=100' => Http::response([
            'total_count' => 101,
            'installations' => [[
                'id' => 101,
                'account' => ['id' => 10, 'login' => 'sakala', 'type' => 'Organization'],
                'repository_selection' => 'selected',
                'permissions' => ['contents' => 'read'],
            ]],
        ]),
    ]);

    $installation = app(GithubInstallationService::class)->setup($user, 101);

    expect($installation->github_installation_id)->toBe(101);
});

test('repository discovery uses the user access token and user-scoped installation endpoint', function (): void {
    $user = User::factory()->create();
    OAuthAccount::factory()->for($user)->create(['access_token' => 'user-access-token']);
    $installation = GithubInstallation::query()->create([
        'github_installation_id' => 100,
        'account_id' => 10,
        'account_login' => 'sakala',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    $installation->users()->attach($user, ['last_verified_at' => now()]);
    Http::fake([
        'https://api.github.com/user/installations/100/repositories*' => Http::response([
            'total_count' => 1,
            'repositories' => [[
                'id' => 123,
                'name' => 'visible-repository',
                'full_name' => 'sakala/visible-repository',
                'clone_url' => 'https://github.com/sakala/visible-repository.git',
                'default_branch' => 'main',
                'pushed_at' => '2026-08-22T00:00:00Z',
                'private' => true,
            ]],
        ]),
    ]);

    $this->actingAs($user, 'web')
        ->getJson(route('api.v1.app.github.installations.repositories.index', $installation))
        ->assertOk()
        ->assertJsonPath('data.0.full_name', 'sakala/visible-repository');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.github.com/user/installations/100/repositories?page=1&per_page=30'
        && $request->hasHeader('Authorization', 'Bearer user-access-token'));
});

test('installation setup rejects missing state', function (): void {
    $this->actingAs(User::factory()->create(), 'web')
        ->get(route('auth.github.setup', ['installation_id' => 100]))
        ->assertForbidden();
});

test('user can configure their personal GitHub App installation', function (): void {
    $user = User::factory()->create();
    $installation = GithubInstallation::query()->create([
        'github_installation_id' => 100,
        'account_id' => 10,
        'account_login' => 'sakala-user',
        'account_type' => 'User',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    $installation->users()->attach($user, ['last_verified_at' => now()]);

    $this->actingAs($user, 'web')
        ->get(route('auth.github.installations.configure', $installation))
        ->assertRedirect('https://github.com/settings/installations/100');

    expect(session('github_app_configure_installation_id'))->toBe(100);
});

test('user can configure their organization GitHub App installation', function (): void {
    $user = User::factory()->create();
    $installation = GithubInstallation::query()->create([
        'github_installation_id' => 100,
        'account_id' => 10,
        'account_login' => 'sakala-organization',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    $installation->users()->attach($user, ['last_verified_at' => now()]);

    $this->actingAs($user, 'web')
        ->get(route('auth.github.installations.configure', $installation))
        ->assertRedirect('https://github.com/organizations/sakala-organization/settings/installations/100');
});

test('user cannot configure another user GitHub App installation', function (): void {
    $installation = GithubInstallation::query()->create([
        'github_installation_id' => 100,
        'account_id' => 10,
        'account_login' => 'sakala',
        'account_type' => 'User',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    $installation->users()->attach(User::factory()->create(), ['last_verified_at' => now()]);

    $this->actingAs(User::factory()->create(), 'web')
        ->get(route('auth.github.installations.configure', $installation))
        ->assertForbidden();
});

test('installation setup accepts a configuration callback for the intended installation', function (): void {
    $user = User::factory()->create();
    OAuthAccount::factory()->for($user)->create(['access_token' => 'user-access-token']);
    Http::fake([
        'https://api.github.com/user/installations*' => Http::response([
            'total_count' => 1,
            'installations' => [[
                'id' => 100,
                'account' => ['id' => 10, 'login' => 'sakala', 'type' => 'User'],
                'repository_selection' => 'selected',
                'permissions' => ['contents' => 'read'],
            ]],
        ]),
    ]);

    $this->actingAs($user, 'web')->withSession(['github_app_configure_installation_id' => 100])
        ->get(route('auth.github.setup', ['installation_id' => 100]))
        ->assertRedirect('http://app.sakala.localhost:5173/dashboard?github_installation=connected');
});
