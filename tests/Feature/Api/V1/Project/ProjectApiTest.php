<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->consoleOrigin = 'http://app.sakala.localhost:5173';
});

// ============================================================================
// LIST PROJECTS TESTS
// ============================================================================

describe('GET /api/v1/app/projects - List Projects', function (): void {
    test('guest cannot list projects', function (): void {
        $this->withHeader('Origin', $this->consoleOrigin)
            ->getJson('/api/v1/app/projects')
            ->assertUnauthorized();
    });

    test('authenticated user can list their own projects', function (): void {
        $user = User::factory()->create();
        $projects = Project::factory()->count(3)->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson('/api/v1/app/projects')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $projects[0]->id);
    });

    test('authenticated user cannot see other users projects', function (): void {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $project1 = Project::factory()->create(['user_id' => $user1->id]);
        Project::factory()->create(['user_id' => $user2->id]);

        $this->actingAs($user1, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson('/api/v1/app/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project1->id);
    });

    test('admin can list all projects', function (): void {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $project1 = Project::factory()->create(['user_id' => $user1->id]);
        $project2 = Project::factory()->create(['user_id' => $user2->id]);

        $this->actingAs($admin, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson('/api/v1/app/projects')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    test('list returns empty array when user has no projects', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson('/api/v1/app/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('list returns paginated response', function (): void {
        $user = User::factory()->create();
        Project::factory()->count(20)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson('/api/v1/app/projects?per_page=15');

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 20);
    });
});

// ============================================================================
// CREATE PROJECT TESTS
// ============================================================================

describe('POST /api/v1/app/projects - Create Project', function (): void {
    test('guest cannot create project', function (): void {
        $this->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ])
            ->assertUnauthorized();
    });

    test('authenticated user can create project with valid data', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Project')
            ->assertJsonPath('data.repository_url', 'https://github.com/test/test')
            ->assertJsonPath('data.branch', 'main')
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.slug', 'test-project')
            ->assertJsonPath('data.default_domain', 'test-project.run.sakala.dev')
            ->assertJsonPath('data.status', ProjectStatus::Draft->value)
            ->assertJsonPath('data.runtime_status', RuntimeStatus::NotDeployed->value);
    });

    test('create project generates unique slug on collision', function (): void {
        $user = User::factory()->create();
        Project::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Project',
            'slug' => 'test-project',
        ]);

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'https://github.com/test/test2',
                'branch' => 'main',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'test-project-1');
    });

    test('create project with reserved slug name gets modified slug', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'api', // reserved slug
                'repository_url' => 'https://github.com/test/api',
                'branch' => 'main',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'api-1');
    });

    test('create project validation fails without name', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('create project validation fails without repository_url', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'branch' => 'main',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository_url']);
    });

    test('create project validation fails with invalid repository_url', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'not-a-url',
                'branch' => 'main',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository_url']);
    });

    test('create project validation fails without branch', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'https://github.com/test/test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch']);
    });

    test('create project validation fails with name too long', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => str_repeat('a', 256),
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('create project validation fails with repository_url too long', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'https://github.com/'.str_repeat('a', 256),
                'branch' => 'main',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository_url']);
    });

    test('create project with optional fields', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
                'repository_provider' => 'gitlab',
                'repository_full_name' => 'test/test',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.repository_provider', 'gitlab')
            ->assertJsonPath('data.repository_full_name', 'test/test');
    });

    test('create project defaults repository_provider to github', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.repository_provider', 'github');
    });
});

// ============================================================================
// SHOW PROJECT TESTS
// ============================================================================

describe('GET /api/v1/app/projects/{project} - Show Project', function (): void {
    test('guest cannot view project', function (): void {
        $project = Project::factory()->create();

        $this->withHeader('Origin', $this->consoleOrigin)
            ->getJson("/api/v1/app/projects/{$project->id}")
            ->assertUnauthorized();
    });

    test('owner can view their project', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson("/api/v1/app/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', $project->name);
    });

    test('user cannot view other users project', function (): void {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($otherUser, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson("/api/v1/app/projects/{$project->id}")
            ->assertForbidden();
    });

    test('admin can view any project', function (): void {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($admin, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson("/api/v1/app/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);
    });

    test('viewing non-existent project returns 404', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson('/api/v1/app/projects/non-existent-id')
            ->assertNotFound();
    });
});

// ============================================================================
// UPDATE PROJECT TESTS
// ============================================================================

describe('PUT /api/v1/app/projects/{project} - Update Project', function (): void {
    test('guest cannot update project', function (): void {
        $project = Project::factory()->create();

        $this->withHeader('Origin', $this->consoleOrigin)
            ->putJson("/api/v1/app/projects/{$project->id}", [
                'name' => 'Updated Project',
            ])
            ->assertUnauthorized();
    });

    test('owner can update their project', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->putJson("/api/v1/app/projects/{$project->id}", [
                'name' => 'Updated Project',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Project')
            ->assertJsonPath('data.slug', 'updated-project');
    });

    test('user cannot update other users project', function (): void {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($otherUser, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->putJson("/api/v1/app/projects/{$project->id}", [
                'name' => 'Updated Project',
            ])
            ->assertForbidden();
    });

    test('admin can update any project', function (): void {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($admin, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->putJson("/api/v1/app/projects/{$project->id}", [
                'name' => 'Updated Project',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Project');
    });

    test('update project with partial data', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name' => 'Original Project',
            'branch' => 'main',
        ]);

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->patchJson("/api/v1/app/projects/{$project->id}", [
                'branch' => 'develop',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Original Project')
            ->assertJsonPath('data.branch', 'develop');
    });

    test('update project validation fails with invalid repository_url', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->putJson("/api/v1/app/projects/{$project->id}", [
                'repository_url' => 'not-a-url',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository_url']);
    });

    test('update project cannot modify user_id', function (): void {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user1->id]);

        // Even if we try to send user_id, it should be ignored by the mass assignment protection
        $response = $this->actingAs($user1, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->putJson("/api/v1/app/projects/{$project->id}", [
                'name' => 'Updated Project',
                'user_id' => $user2->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.user_id', $user1->id);
    });

    test('update non-existent project returns 404', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->putJson('/api/v1/app/projects/non-existent-id', [
                'name' => 'Updated Project',
            ])
            ->assertNotFound();
    });
});

// ============================================================================
// DELETE PROJECT TESTS
// ============================================================================

describe('DELETE /api/v1/app/projects/{project} - Delete Project', function (): void {
    test('guest cannot delete project', function (): void {
        $project = Project::factory()->create();

        $this->withHeader('Origin', $this->consoleOrigin)
            ->deleteJson("/api/v1/app/projects/{$project->id}")
            ->assertUnauthorized();
    });

    test('owner can delete their project', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->deleteJson("/api/v1/app/projects/{$project->id}")
            ->assertNoContent();

        expect(Project::find($project->id))->toBeNull();
    });

    test('user cannot delete other users project', function (): void {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($otherUser, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->deleteJson("/api/v1/app/projects/{$project->id}")
            ->assertForbidden();

        expect(Project::find($project->id))->not->toBeNull();
    });

    test('admin can delete any project', function (): void {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($admin, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->deleteJson("/api/v1/app/projects/{$project->id}")
            ->assertNoContent();

        expect(Project::find($project->id))->toBeNull();
    });

    test('delete project uses soft delete', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->deleteJson("/api/v1/app/projects/{$project->id}")
            ->assertNoContent();

        expect(Project::withTrashed()->find($project->id))->not->toBeNull();
        expect(Project::find($project->id))->toBeNull();
    });

    test('delete non-existent project returns 404', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->deleteJson('/api/v1/app/projects/non-existent-id')
            ->assertNotFound();
    });
});

// ============================================================================
// SLUG AND DOMAIN TESTS
// ============================================================================

describe('Slug and Domain Generation', function (): void {
    test('create project with unicode name generates valid slug', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Proyek 🚀 Saya',
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'proyek-saya')
            ->assertJsonPath('data.default_domain', 'proyek-saya.run.sakala.dev');
    });

    test('create project with special characters generates valid slug', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'My_Project-Name',
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'my-project-name');
    });

    test('create project with empty name generates fallback slug', function (): void {
        $user = User::factory()->create();

        // This should fail validation because name is required
        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => '   ',
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('create project with name that generates empty slug gets fallback', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => '🚀', // This should generate an empty slug
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ]);

        $response->assertCreated();
        $slug = $response->json('data.slug');
        expect($slug)->toStartWith('project-');
    });

    test('create project with very long name generates truncated slug', function (): void {
        $user = User::factory()->create();
        $longName = str_repeat('a', 100);

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => $longName,
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
            ]);

        $response->assertCreated();
        $slug = $response->json('data.slug');
        expect(strlen($slug))->toBeLessThanOrEqual(63);
    });
});

// ============================================================================
// AUTHORIZATION EDGE CASES
// ============================================================================

describe('Authorization Edge Cases', function (): void {
    test('user cannot access soft-deleted project from another user', function (): void {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->delete();

        $this->actingAs($otherUser, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson("/api/v1/app/projects/{$project->id}")
            ->assertForbidden();
    });

    test('owner can access their soft-deleted project', function (): void {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $project->delete();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson("/api/v1/app/projects/{$project->id}")
            ->assertOk();
    });

    test('soft-deleted projects do not appear in list for owner', function (): void {
        $user = User::factory()->create();
        $project1 = Project::factory()->create(['user_id' => $user->id]);
        $project2 = Project::factory()->create(['user_id' => $user->id]);
        $project2->delete();

        $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->getJson('/api/v1/app/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project1->id);
    });

    test('server-owned fields are not mass assignable', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withHeader('Origin', $this->consoleOrigin)
            ->postJson('/api/v1/app/projects', [
                'name' => 'Test Project',
                'repository_url' => 'https://github.com/test/test',
                'branch' => 'main',
                'status' => 'active', // server-owned
                'runtime_status' => 'running', // server-owned
                'detected_port' => 3000, // server-owned
                'last_deployed_at' => now()->toIsoString(), // server-owned
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', ProjectStatus::Draft->value)
            ->assertJsonPath('data.runtime_status', RuntimeStatus::NotDeployed->value)
            ->assertJsonPath('data.detected_port', null)
            ->assertJsonPath('data.last_deployed_at', null);
    });
});
