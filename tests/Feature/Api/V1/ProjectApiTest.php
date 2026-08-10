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
