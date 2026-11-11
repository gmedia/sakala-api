<?php

declare(strict_types=1);

use App\Actions\Admin\ChangeUserRoleAction;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Exceptions\Admin\LastAdminException;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = adminApiAdmin(['name' => 'Admin Satu', 'email' => 'admin@sakala.test']);
});

test('admins list users with search, role, and verification filters', function (): void {
    User::factory()->create(['name' => 'Rina Kampus', 'email' => 'rina@kampus.test']);
    User::factory()->unverified()->create(['name' => 'Budi', 'email' => 'budi@example.test']);
    adminApiAdmin(['name' => 'Admin Dua']);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/users?search=KAMPUS')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'rina@kampus.test')
        ->assertJsonPath('data.0.created_projects_count', 0);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/users?role=admin')
        ->assertJsonCount(2, 'data');

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/users?verified=0')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email_verified', false);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/users?per_page=2&page=2')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.per_page', 2);
});

test('user resources never carry password hashes, tokens, or settings', function (): void {
    $user = User::factory()->create();

    $body = $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/users/{$user->id}")->assertOk()->getContent();

    expect($body)->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('notification_preferences')
        ->not->toContain((string) $user->getAttributes()['password']);
});

test('user detail lists workspaces with roles, project counts, limits, and recent audit events', function (): void {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, [[$user, WorkspaceRole::Viewer]], ['name' => 'Tim Magang']);
    Project::factory()->for($user)->create();
    Project::factory()->inWorkspace($team, $owner)->count(2)->create();
    AuditEvent::query()->create([
        'actor_type' => User::class, 'actor_id' => (string) $user->id, 'action' => 'account.something',
        'subject_type' => User::class, 'subject_id' => (string) $user->id, 'metadata' => [],
    ]);

    $response = $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/users/{$user->id}")->assertOk();

    $response
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.created_projects_count', 1)
        ->assertJsonPath('data.owned_teams_count', 0)
        ->assertJsonPath('data.effective_max_owned_teams', 2)
        ->assertJsonPath('data.workspaces.0.type', 'personal')
        ->assertJsonPath('data.workspaces.0.role', 'owner')
        ->assertJsonPath('data.workspaces.0.projects_count', 1)
        ->assertJsonPath('data.workspaces.1.name', 'Tim Magang')
        ->assertJsonPath('data.workspaces.1.role', 'viewer')
        ->assertJsonPath('data.workspaces.1.projects_count', 2)
        ->assertJsonPath('data.personal_workspace_quota.limit_profile', null)
        ->assertJsonPath('data.personal_workspace_quota.limits.max_projects', 3)
        ->assertJsonPath('data.personal_workspace_quota.usage.projects', 1)
        ->assertJsonPath('data.recent_audit_events.0.action', 'account.something')
        ->assertJsonPath('data.recent_audit_events.0.actor.type', 'user')
        ->assertJsonPath('data.recent_audit_events.0.actor.username', $user->username);
});

test('an admin promotes and demotes another user with a reason and an audit event', function (): void {
    $user = User::factory()->create();

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'admin', 'reason' => 'Maintainer baru'])
        ->assertOk()
        ->assertJsonPath('data.role', 'admin');

    expect($user->refresh()->isAdmin())->toBeTrue();

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'user', 'reason' => 'Selesai bertugas'])
        ->assertOk()
        ->assertJsonPath('data.role', 'user');

    $events = AuditEvent::query()->where('action', 'user.role_changed')->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->subject_id)->toBe((string) $user->id)
        ->and($events[0]->actor_id)->toBe((string) $this->admin->id)
        ->and($events[0]->metadata)->toBe(['reason' => 'Maintainer baru', 'from' => 'user', 'to' => 'admin'])
        ->and($events[1]->metadata['to'])->toBe('user');
});

test('setting the role a user already has records nothing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'user', 'reason' => 'Cek'])
        ->assertOk();

    expect(AuditEvent::query()->where('action', 'user.role_changed')->count())->toBe(0);
});

test('an admin cannot demote themselves', function (): void {
    adminApiAdmin();

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/users/{$this->admin->id}/role", ['role' => 'user', 'reason' => 'Mundur'])
        ->assertConflict()
        ->assertJsonPath('code', 'ADMIN_SELF_DEMOTION');

    expect($this->admin->refresh()->isAdmin())->toBeTrue();
});

test('the last admin cannot be demoted, even when two admins demote each other at once', function (): void {
    $other = adminApiAdmin();

    // The other admin's demotion of this admin committed first.
    User::query()->whereKey($this->admin->id)->update(['role' => UserRole::User]);

    expect(fn () => app(ChangeUserRoleAction::class)->handle($this->admin, $other, UserRole::User, 'Balasan'))
        ->toThrow(LastAdminException::class);

    expect($other->refresh()->isAdmin())->toBeTrue();
});

test('a role change needs a valid role and a reason', function (): void {
    $user = User::factory()->create();

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => 'owner'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role', 'reason']);
});

test('an admin sets and clears a user team cap', function (): void {
    $user = User::factory()->create();

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/users/{$user->id}/team-limit", ['max_owned_teams' => 5, 'reason' => 'Dosen pengampu'])
        ->assertOk()
        ->assertJsonPath('data.max_owned_teams', 5);

    expect($user->refresh()->maxOwnedTeams())->toBe(5);

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/users/{$user->id}/team-limit", ['max_owned_teams' => null, 'reason' => 'Semester selesai'])
        ->assertOk()
        ->assertJsonPath('data.max_owned_teams', null);

    expect($user->refresh()->maxOwnedTeams())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'user.max_owned_teams_changed')->pluck('metadata')->all())->toBe([
            ['reason' => 'Dosen pengampu', 'from' => null, 'to' => 5],
            ['reason' => 'Semester selesai', 'from' => 5, 'to' => null],
        ]);

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/users/{$user->id}/team-limit", ['reason' => 'Lupa nilai'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_owned_teams');
});

test('an admin attaches a limit profile to a user personal workspace and clears it', function (): void {
    $user = User::factory()->create();
    $profile = LimitProfile::factory()->create(['name' => 'Magang GMEDIA', 'max_projects' => 8]);
    $personal = Workspace::query()->where('personal_user_id', $user->id)->firstOrFail();

    $this->actingAs($this->admin, 'web')
        ->putJson("/api/v1/admin/users/{$user->id}/limit-profile", ['limit_profile_id' => $profile->id, 'reason' => 'Magang'])
        ->assertOk()
        ->assertJsonPath('data.id', $personal->id)
        ->assertJsonPath('data.limit_profile.name', 'Magang GMEDIA');

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/users/{$user->id}")
        ->assertJsonPath('data.personal_workspace_quota.limits.max_projects', 8);

    $this->actingAs($this->admin, 'web')
        ->putJson("/api/v1/admin/users/{$user->id}/limit-profile", ['limit_profile_id' => null, 'reason' => 'Selesai'])
        ->assertOk()
        ->assertJsonPath('data.limit_profile', null);

    $events = AuditEvent::query()->where('action', 'workspace.limit_profile_changed')->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->subject_id)->toBe($personal->id)
        ->and($events[0]->metadata)->toBe(['from_limit_profile_id' => null, 'to_limit_profile_id' => $profile->id, 'reason' => 'Magang']);

    $this->actingAs($this->admin, 'web')
        ->putJson("/api/v1/admin/users/{$user->id}/limit-profile", ['limit_profile_id' => '0193b6a0-0000-7000-8000-000000000000', 'reason' => 'Salah'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit_profile_id');
});
