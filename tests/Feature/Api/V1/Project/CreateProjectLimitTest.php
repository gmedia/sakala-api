<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can create projects up to the pilot limit', function () {
    config(['sakala.pilot_limits.max_projects_per_user' => 3]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $this->actingAs($user, 'web');

    // Create 1st project
    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project One',
        'repository_url' => 'https://github.com/example/project-one',
        'branch' => 'main',
    ])->assertCreated();

    // Create 2nd project
    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project Two',
        'repository_url' => 'https://github.com/example/project-two',
        'branch' => 'main',
    ])->assertCreated();

    // Create 3rd project
    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project Three',
        'repository_url' => 'https://github.com/example/project-three',
        'branch' => 'main',
    ])->assertCreated();

    expect(Project::where('user_id', $user->id)->count())->toBe(3);
});

test('authenticated user receives actionable error when exceeding project limit', function () {
    config(['sakala.pilot_limits.max_projects_per_user' => 3]);

    $user = User::factory()->create(['role' => UserRole::User]);
    Project::factory()->for($user)->count(3)->create();

    $this->actingAs($user, 'web');

    $response = $this->postJson('/api/v1/app/projects', [
        'name' => 'Over Limit Project',
        'repository_url' => 'https://github.com/example/project-four',
        'branch' => 'main',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'code' => 'PROJECT_LIMIT_EXCEEDED',
            'limit' => 3,
            'current' => 3,
        ])
        ->assertJsonStructure([
            'message',
            'code',
            'limit',
            'current',
        ]);
});

test('soft-deleted projects do not count towards project creation quota', function () {
    config(['sakala.pilot_limits.max_projects_per_user' => 2]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $projects = Project::factory()->for($user)->count(2)->create();

    $this->actingAs($user, 'web');

    // 3rd attempt fails
    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project Three',
        'repository_url' => 'https://github.com/example/project-three',
        'branch' => 'main',
    ])->assertUnprocessable();

    // Delete one project
    $this->deleteJson("/api/v1/app/projects/{$projects->first()->id}")
        ->assertNoContent();

    // Now user can create a project again
    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project Three',
        'repository_url' => 'https://github.com/example/project-three',
        'branch' => 'main',
    ])->assertCreated();

    expect(Project::where('user_id', $user->id)->count())->toBe(2);
});

test('admin user can exceed project creation limit', function () {
    config(['sakala.pilot_limits.max_projects_per_user' => 1]);

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Project::factory()->for($admin)->count(2)->create();

    $this->actingAs($admin, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Admin Project',
        'repository_url' => 'https://github.com/example/admin-project',
        'branch' => 'main',
    ])->assertCreated();
});
