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

test('guest cannot update a project', function (): void {
    $project = Project::factory()->create();

    $this->putJson("/api/v1/app/projects/{$project->id}", [
        'name' => 'Updated Project Name',
    ])->assertUnauthorized();
});

test('returns 404 when project is not found', function (): void {
    $user = User::factory()->create();
    $nonExistentId = (string) Str::uuid();

    $this->actingAs($user)
        ->getJson("/api/v1/app/projects/{$nonExistentId}")
        ->assertNotFound();
});

test('update returns 404 when project is not found', function (): void {
    $user = User::factory()->create();
    $nonExistentId = (string) Str::uuid();

    $this->actingAs($user)
        ->putJson("/api/v1/app/projects/{$nonExistentId}", [
            'name' => 'Updated Project Name',
        ])
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

test('server-owned project fields cannot be changed through update', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create([
        'user_id' => $owner->id,
        'detected_port' => 8080,
        'last_deployed_at' => now()->subDay(),
    ]);
    $originalProject = $project->fresh();

    $this->actingAs($owner)
        ->putJson("/api/v1/app/projects/{$project->id}", [
            'name' => 'Updated Project Name',
            'id' => (string) Str::uuid(),
            'user_id' => $otherUser->id,
            'slug' => 'hacked-project',
            'repository_provider' => 'gitlab',
            'repository_full_name' => 'attacker/hacked-project',
            'default_domain' => 'hacked.run.sakala.localhost',
            'status' => 'active',
            'runtime_status' => 'running',
            'detected_port' => 9999,
            'last_deployed_at' => now()->toAtomString(),
        ])
        ->assertOk();

    $project->refresh();

    expect($project->name)->toBe('Updated Project Name')
        ->and($project->id)->toBe($originalProject->id)
        ->and($project->user_id)->toBe($originalProject->user_id)
        ->and($project->slug)->toBe($originalProject->slug)
        ->and($project->repository_provider)->toBe($originalProject->repository_provider)
        ->and($project->repository_full_name)->toBe($originalProject->repository_full_name)
        ->and($project->default_domain)->toBe($originalProject->default_domain)
        ->and($project->status)->toEqual($originalProject->status)
        ->and($project->runtime_status)->toEqual($originalProject->runtime_status)
        ->and($project->detected_port)->toBe($originalProject->detected_port)
        ->and($project->last_deployed_at?->toAtomString())
        ->toBe($originalProject->last_deployed_at?->toAtomString());
});

test('authenticated user can clear their thumbnail with null', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'thumbnail_url' => 'https://example.com/existing-thumbnail.png',
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/app/projects/{$project->id}", [
            'thumbnail_url' => null,
        ])
        ->assertOk()
        ->assertJson([
            'data' => [
                'thumbnail_url' => null,
            ],
        ]);

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'thumbnail_url' => null,
    ]);
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

test('guest cannot delete a project', function (): void {
    $project = Project::factory()->create();

    $this->deleteJson("/api/v1/app/projects/{$project->id}")
        ->assertUnauthorized();
});

test('delete returns 404 when project is not found', function (): void {
    $user = User::factory()->create();
    $nonExistentId = (string) Str::uuid();

    $this->actingAs($user)
        ->deleteJson("/api/v1/app/projects/{$nonExistentId}")
        ->assertNotFound();
});

test('user cannot delete another user project', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($otherUser)
        ->deleteJson("/api/v1/app/projects/{$project->id}")
        ->assertForbidden();
});
