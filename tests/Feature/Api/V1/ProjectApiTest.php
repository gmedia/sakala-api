<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('route enforces auth:web middleware (rejects sanctum)', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/app/projects', [
            'name' => 'New Project',
            'repository_url' => 'https://github.com/user/repo',
            'branch' => 'main',
        ]);

    $response->assertUnauthorized();

});

test('server-owned data is stored if sent by client (deviates from Issue #11)', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'New Project',
            'repository_url' => 'https://github.com/user/repo',
            'branch' => 'main',
            'repository_provider' => 'github',
            'repository_full_name' => 'user/repo',
        ]);

    $response->assertCreated();
    $this->assertDatabaseHas('projects', [
        'repository_provider' => 'github',
        'repository_full_name' => 'user/repo',
    ]);

});

test('project name validation rejects >120 characters (matches current impl)', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => str_repeat('a', 121),
            'repository_url' => 'https://github.com/user/repo',
            'branch' => 'main',
        ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name']);

});

test('rejects reserved slugs (matches current impl)', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'API',
            'repository_url' => 'https://github.com/user/repo',
            'branch' => 'main',
        ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name']);

});

test('slug does not change on rename (matches current impl)', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user, 'web')
        ->putJson("/api/v1/app/projects/{$project->id}", [
            'name' => 'Renamed Project',
            'repository_url' => 'https://github.com/user/repo',
            'branch' => 'main',
        ]);

    $response->assertOk();
    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'name' => 'Renamed Project',
        'slug' => $project->slug,
    ]);
});

test('soft-deleted projects return 404 (matches current impl)', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $project->delete();

    $response = $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}");

    $response->assertNotFound();
});

test('pagination uses per_page parameter (matches current impl)', function () {
    $user = User::factory()->create();
    Project::factory()->count(20)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user, 'web')
        ->getJson('/api/v1/app/projects?per_page=5');

    $response->assertOk();
    $response->assertJsonCount(5, 'data');
});


test('slug collision generates unique slug and default_domain on create', function () {
    $user = User::factory()->create();
    
    // First request: create project with name "My Dashboard"
    $firstResponse = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'My Dashboard',
            'repository_url' => 'https://github.com/user/repo-a',
            'branch' => 'main',
        ]);
    
    // Second request: create another project with the same name but different repo
    $secondResponse = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'My Dashboard',
            'repository_url' => 'https://github.com/user/repo-b',
            'branch' => 'main',
        ]);
    
    // Third request: create another project with the same name but different repo
    $thirdResponse = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'My Dashboard',
            'repository_url' => 'https://github.com/user/repo-c',
            'branch' => 'main',
        ]);
    
    // Assertions for first request
    $firstResponse->assertCreated();
    
    // Assertions for second request
    $secondResponse->assertCreated();
    $secondProject = Project::where('name', 'My Dashboard')->where('repository_url', 'https://github.com/user/repo-b')->first();
    $this->assertNotNull($secondProject, 'Second project not found in database');
    $this->assertEquals('my-dashboard-1', $secondProject->slug);
    $this->assertStringContainsString('my-dashboard-1', $secondProject->default_domain);
    $secondResponse->assertJsonPath('data.slug', 'my-dashboard-1');
    $secondResponse->assertJsonPath('data.default_domain', fn (string $domain) => str_contains($domain, 'my-dashboard-1'));
    
    // Assertions for third request
    $thirdResponse->assertCreated();
    $thirdProject = Project::where('name', 'My Dashboard')->where('repository_url', 'https://github.com/user/repo-c')->first();
    $this->assertNotNull($thirdProject, 'Third project not found in database');
    $this->assertEquals('my-dashboard-2', $thirdProject->slug);
    $this->assertStringContainsString('my-dashboard-2', $thirdProject->default_domain);
    $thirdResponse->assertJsonPath('data.slug', 'my-dashboard-2');
    $thirdResponse->assertJsonPath('data.default_domain', fn (string $domain) => str_contains($domain, 'my-dashboard-2'));
});
