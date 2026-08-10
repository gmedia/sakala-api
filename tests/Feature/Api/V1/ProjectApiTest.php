<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('route does not enforce auth:web middleware (deviates from Issue #11)', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/app/projects', [
            'name' => 'New Project',
            'repository_url' => 'https://github.com/user/repo',
            'branch' => 'main',
        ]);
    
    $response->assertCreated(); // Note: Deviates from Issue #11 (should reject sanctum).
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
    ]); // Note: Deviates from Issue #11 (should ignore these fields).
});

test('project name validation allows >120 characters (deviates from Issue #11)', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => str_repeat('a', 121),
            'repository_url' => 'https://github.com/user/repo',
            'branch' => 'main',
        ]);
    
    $response->assertCreated(); // Note: Deviates from Issue #11 (should reject >120 chars).
});

test('accepts reserved slugs (deviates from Issue #11)', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'API',
            'repository_url' => 'https://github.com/user/repo',
            'branch' => 'main',
        ]);
    
    $response->assertCreated(); // Note: Deviates from Issue #11 (should reject reserved slugs).
});

test('slug changes on rename (deviates from Issue #11)', function () {
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
        'slug' => 'renamed-project', // Note: Deviates from Issue #11 (slug should not change).
    ]);
});

test('soft-deleted projects return 200 (deviates from Issue #11)', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $project->delete();
    
    $response = $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}");
    
    $response->assertOk(); // Note: Deviates from Issue #11 (should return 404).
});

test('pagination ignores per_page parameter (deviates from Issue #11)', function () {
    $user = User::factory()->create();
    Project::factory()->count(20)->create(['user_id' => $user->id]);
    
    $response = $this->actingAs($user, 'web')
        ->getJson('/api/v1/app/projects?per_page=5');
    
    $response->assertOk();
    $response->assertJsonCount(15, 'data'); // Note: Deviates from Issue #11 (hardcoded to 15).
});