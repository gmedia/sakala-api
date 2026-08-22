<?php

declare(strict_types=1);

use App\Enums\GithubInstallationStatus;
use App\Models\GithubInstallation;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('Authenticated user can create a project', function () {

    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $response = $this->postJson('/api/v1/app/projects', [
        'name' => 'Ichikiwir',
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir.git',
        'branch' => 'main',
    ]);

    $response
        ->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'repository_full_name',
                'repository_source',
                'github_installation_id',
                'github_repository_id',
                'branch',
                'runtime_status',
                'created_at',
            ],
        ]);
    $this->assertDatabaseHas('projects', [
        'user_id' => $user->id,
        'name' => 'Ichikiwir',
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir',
        'branch' => 'main',
    ]);
    $response->assertJsonPath('data.repository_source', 'public_url');
});

test('Guest cannot create a project', function () {
    $response = $this->postJson('/api/v1/app/projects', [
        'name' => 'Ichikiwir',
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir',
        'branch' => 'main',
    ]);

    $response->assertUnauthorized();
});

test('Repository url must be string', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => [],
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository.url',
        ]);
});

test('Repository url must be valid github repository', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://gitlab.com/Ngab-Rio/ichikiwir.git',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository.url',
        ]);
});

test('Repository url must use https', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'http://github.com/Ngab-Rio/Karaoke-API',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository.url',
        ]);
});

test('Repository url must not contain query string', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API?foo=bar',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository.url',
        ]);
});

test('Repository url must not contain fragment', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API#readme',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository.url',
        ]);
});

test('Repository URL is stored in canonical format', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://www.github.com/Ngab-Rio/ichikiwir.git',
        'branch' => 'main',
    ])->assertCreated();

    $this->assertDatabaseHas('projects', [
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir',
    ]);
});

test('Repository url must not contain credentials', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://user:password@github.com/Ngab-Rio/Karaoke-API',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository.url',
        ]);
});

test('Name is required', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'name',
        ]);
});

test('Branch is required', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'branch',
        ]);
});

test('project creation verifies an installation repository with the user access token', function (): void {
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
                'full_name' => 'sakala/private-repository',
                'clone_url' => 'https://github.com/sakala/private-repository.git',
            ]],
        ]),
    ]);

    $this->actingAs($user, 'web')->postJson('/api/v1/app/projects', [
        'name' => 'Private Project',
        'branch' => 'main',
        'repository' => [
            'type' => 'github_installation',
            'installation_id' => $installation->id,
            'repository_id' => 123,
        ],
    ])->assertCreated()
        ->assertJsonPath('data.repository_source', 'github_installation')
        ->assertJsonPath('data.github_repository_id', 123);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.github.com/user/installations/100/repositories?page=1&per_page=100'
        && $request->hasHeader('Authorization', 'Bearer user-access-token'));
});

test('project creation rejects a repository outside the user installation scope', function (): void {
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
            'total_count' => 0,
            'repositories' => [],
        ]),
    ]);

    $this->actingAs($user, 'web')->postJson('/api/v1/app/projects', [
        'name' => 'Unavailable Project',
        'branch' => 'main',
        'repository' => [
            'type' => 'github_installation',
            'installation_id' => $installation->id,
            'repository_id' => 123,
        ],
    ])->assertForbidden();
});
