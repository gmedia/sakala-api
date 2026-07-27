<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('authenticated user can view their project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'thumbnail_url' => 'https://example.com/thumbnail.png',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'thumbnail_url' => 'https://example.com/thumbnail.png',
                'repository_url' => $project->repository_url,
                'branch' => $project->branch,
                'status' => $project->status->value,
                'runtime_status' => $project->runtime_status->value,
            ],
        ]);
});

test('user cannot view another user project', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($otherUser)
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertForbidden();
});

test('guest cannot view project', function (): void {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/app/projects/{$project->id}")
        ->assertUnauthorized();
});

test('returns 404 when project is not found', function (): void {
    $user = User::factory()->create();
    $nonExistentId = (string) Str::uuid();

    $this->actingAs($user)
        ->getJson("/api/v1/app/projects/{$nonExistentId}")
        ->assertNotFound();
});

test('authenticated user can update their project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $updatePayload = [
        'name' => 'Updated Project Name',
        'thumbnail_url' => 'https://example.com/new-thumbnail.png',
        'branch' => 'develop',
    ];

    $this->actingAs($user)
        ->putJson("/api/v1/app/projects/{$project->id}", $updatePayload)
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $project->id,
                'name' => 'Updated Project Name',
                'thumbnail_url' => 'https://example.com/new-thumbnail.png',
                'branch' => 'develop',
            ],
        ]);

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'name' => 'Updated Project Name',
        'thumbnail_url' => 'https://example.com/new-thumbnail.png',
        'branch' => 'develop',
    ]);
});

test('user cannot update another user project', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($otherUser)
        ->putJson("/api/v1/app/projects/{$project->id}", [
            'name' => 'Hacked Project Name',
        ])
        ->assertForbidden();
});

test('update project validates input fields', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->putJson("/api/v1/app/projects/{$project->id}", [
            'thumbnail_url' => 'invalid-url',
            'repository_url' => 'invalid-url',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['thumbnail_url', 'repository_url']);
});

test('authenticated user can delete their project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/app/projects/{$project->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('projects', [
        'id' => $project->id,
    ]);
});

test('user cannot delete another user project', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($otherUser)
        ->deleteJson("/api/v1/app/projects/{$project->id}")
        ->assertForbidden();
});
