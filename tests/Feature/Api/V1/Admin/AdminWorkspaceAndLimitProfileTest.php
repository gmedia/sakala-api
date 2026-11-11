<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = adminApiAdmin();
});

test('admins list workspaces filtered by type, search, and profile', function (): void {
    $owner = User::factory()->create(['name' => 'Pemilik']);
    $profile = LimitProfile::factory()->create();
    $team = teamWorkspace($owner, [[User::factory()->create(), WorkspaceRole::Member]], ['name' => 'GMEDIA Facility', 'limit_profile_id' => $profile->id]);
    Project::factory()->inWorkspace($team)->create();

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/workspaces?type=team')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'GMEDIA Facility')
        ->assertJsonPath('data.0.members_count', 2)
        ->assertJsonPath('data.0.projects_count', 1)
        ->assertJsonPath('data.0.personal_user', null)
        ->assertJsonPath('data.0.limit_profile.id', $profile->id);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/workspaces?type=personal')
        ->assertJsonCount(3, 'data');

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/workspaces?search=facility')
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/workspaces?limit_profile_id={$profile->id}")
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/workspaces?type=org')
        ->assertUnprocessable();
});

test('workspace detail shows members with email, projects, limits, and usage', function (): void {
    $owner = User::factory()->create(['email' => 'owner@team.test']);
    $team = teamWorkspace($owner, [[User::factory()->create(), WorkspaceRole::Viewer]]);
    Project::factory()->inWorkspace($team)->create(['name' => 'facility-reporting']);

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/workspaces/{$team->id}")
        ->assertOk()
        ->assertJsonPath('data.workspace.id', $team->id)
        ->assertJsonPath('data.members.0.role', 'owner')
        ->assertJsonPath('data.members.0.user.email', 'owner@team.test')
        ->assertJsonPath('data.members.1.role', 'viewer')
        ->assertJsonPath('data.projects.0.name', 'facility-reporting')
        ->assertJsonPath('data.quota.limit_profile', null)
        ->assertJsonPath('data.quota.limits.max_projects', 2)
        ->assertJsonPath('data.quota.usage.projects', 1);
});

test('an admin assigns and clears a workspace limit profile with a reason', function (): void {
    $team = teamWorkspace(User::factory()->create());
    $profile = LimitProfile::factory()->create(['max_projects' => 10]);

    $this->actingAs($this->admin, 'web')
        ->putJson("/api/v1/admin/workspaces/{$team->id}/limit-profile", ['limit_profile_id' => $profile->id, 'reason' => 'Kelas'])
        ->assertOk()
        ->assertJsonPath('data.limit_profile.id', $profile->id);

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/workspaces/{$team->id}")
        ->assertJsonPath('data.quota.limits.max_projects', 10);

    $this->actingAs($this->admin, 'web')
        ->putJson("/api/v1/admin/workspaces/{$team->id}/limit-profile", ['limit_profile_id' => null, 'reason' => 'Kelas selesai'])
        ->assertOk()
        ->assertJsonPath('data.limit_profile', null);

    expect(AuditEvent::query()->where('action', 'workspace.limit_profile_changed')->pluck('metadata')->pluck('reason')->all())
        ->toBe(['Kelas', 'Kelas selesai']);

    $this->actingAs($this->admin, 'web')
        ->putJson("/api/v1/admin/workspaces/{$team->id}/limit-profile", ['limit_profile_id' => $profile->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

test('admins create, read, update, and list limit profiles', function (): void {
    $response = $this->actingAs($this->admin, 'web')->postJson('/api/v1/admin/limit-profiles', [
        'name' => 'Kelas Cloud',
        'max_projects' => 4,
        'max_active_containers' => 3,
        'max_active_deployments' => 1,
        'max_memory_mb' => 256,
        'max_cpu_millis' => 500,
        'reason' => 'Kelas semester ganjil',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Kelas Cloud')
        ->assertJsonPath('data.max_builds_per_hour', null)
        ->assertJsonPath('data.workspaces_count', 0);

    $id = $response->json('data.id');

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_projects' => 6, 'reason' => 'Tambah tugas'])
        ->assertOk()
        ->assertJsonPath('data.max_projects', 6)
        ->assertJsonPath('data.max_memory_mb', 256);

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/limit-profiles/{$id}")
        ->assertOk()
        ->assertJsonPath('data.max_projects', 6);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/limit-profiles?search=cloud')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $updated = AuditEvent::query()->where('action', 'limit_profile.updated')->sole();
    expect($updated->metadata)->toBe(['reason' => 'Tambah tugas', 'from' => ['max_projects' => 4], 'to' => ['max_projects' => 6]])
        ->and(AuditEvent::query()->where('action', 'limit_profile.created')->sole()->subject_id)->toBe($id);
});

test('limit profile validation rejects duplicates, missing fields, and out-of-range values', function (): void {
    $existing = LimitProfile::factory()->create(['name' => 'Pilot']);

    $this->actingAs($this->admin, 'web')->postJson('/api/v1/admin/limit-profiles', [
        'name' => 'Pilot', 'max_projects' => 0, 'reason' => 'x',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'max_projects', 'max_active_containers', 'max_memory_mb']);

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/limit-profiles/{$existing->id}", ['name' => 'Pilot', 'reason' => 'sama'])
        ->assertOk();
});

test('a limit profile in use cannot be deleted; an unused one can', function (): void {
    $profile = LimitProfile::factory()->create();
    teamWorkspace(User::factory()->create(), [], ['limit_profile_id' => $profile->id]);
    teamWorkspace(User::factory()->create(), [], ['limit_profile_id' => $profile->id]);

    $this->actingAs($this->admin, 'web')
        ->deleteJson("/api/v1/admin/limit-profiles/{$profile->id}", ['reason' => 'Tidak dipakai'])
        ->assertConflict()
        ->assertJsonPath('code', 'LIMIT_PROFILE_IN_USE')
        ->assertJsonPath('workspaces', 2);

    expect(LimitProfile::query()->whereKey($profile->id)->exists())->toBeTrue();

    $unused = LimitProfile::factory()->create();

    $this->actingAs($this->admin, 'web')
        ->deleteJson("/api/v1/admin/limit-profiles/{$unused->id}", ['reason' => 'Duplikat'])
        ->assertNoContent();

    expect(LimitProfile::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'limit_profile.deleted')->sole()->metadata['reason'])->toBe('Duplikat');

    $this->actingAs($this->admin, 'web')
        ->deleteJson("/api/v1/admin/limit-profiles/{$profile->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});
