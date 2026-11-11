<?php

declare(strict_types=1);

use App\Actions\Workspace\AssignLimitProfileAction;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('every user has exactly one personal workspace and lists it first', function (): void {
    $user = User::factory()->create(['name' => 'Rani Putri']);
    $team = teamWorkspace($user, attributes: ['name' => 'Aaa Team']);

    $response = $this->actingAs($user, 'web')->getJson('/api/v1/app/workspaces');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'personal')
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.0.name', 'Rani Putri')
        ->assertJsonPath('data.0.slug', $user->username)
        ->assertJsonPath('data.0.limit_profile', null)
        ->assertJsonPath('data.1.id', $team->id)
        ->assertJsonPath('data.1.type', 'team');

    expect(Workspace::query()->where('personal_user_id', $user->id)->count())->toBe(1);
});

test('registration creates the personal workspace with the account', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Budi Santoso',
        'email' => 'budi@example.test',
        'password' => 'Str0ng-Password!x',
        'password_confirmation' => 'Str0ng-Password!x',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'budi@example.test')->firstOrFail();
    $workspace = $user->personalWorkspace()->firstOrFail();

    expect($workspace->type)->toBe(WorkspaceType::Personal)
        ->and($workspace->roleOf($user))->toBe(WorkspaceRole::Owner);
});

test('a verified user creates a team and becomes its owner', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'Tim Magang GMEDIA']);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Tim Magang GMEDIA')
        ->assertJsonPath('data.slug', 'tim-magang-gmedia')
        ->assertJsonPath('data.type', 'team')
        ->assertJsonPath('data.role', 'owner');

    $workspace = Workspace::query()->findOrFail($response->json('data.id'));

    expect($workspace->roleOf($user))->toBe(WorkspaceRole::Owner)
        ->and(AuditEvent::query()->where('action', 'workspace.created')->where('subject_id', $workspace->id)->exists())->toBeTrue();
});

test('a chosen slug is validated and must be free', function (): void {
    $user = User::factory()->create();
    teamWorkspace(User::factory()->create(), attributes: ['slug' => 'taken']);

    $this->actingAs($user, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'X', 'slug' => 'taken'])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->actingAs($user, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'X', 'slug' => 'Bad Slug'])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->actingAs($user, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'X', 'slug' => 'kelas-a'])
        ->assertCreated()->assertJsonPath('data.slug', 'kelas-a');
});

test('a generated slug avoids one already taken', function (): void {
    $user = User::factory()->create();
    teamWorkspace(User::factory()->create(), attributes: ['slug' => 'kelas']);

    $slug = $this->actingAs($user, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'Kelas'])
        ->assertCreated()->json('data.slug');

    expect($slug)->toStartWith('kelas-')->not->toBe('kelas');
});

test('an unverified user cannot create a team', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'Tim'])
        ->assertForbidden()
        ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
});

test('owned teams are capped per user, the cap can be raised per user, and admins are not capped', function (): void {
    config(['sakala.workspaces.max_owned_teams_per_user' => 2]);
    $user = User::factory()->create();
    teamWorkspace($user);
    teamWorkspace($user);
    // A team where the user is only a member does not count.
    teamWorkspace(User::factory()->create(), [[$user, WorkspaceRole::Member]]);

    $this->actingAs($user, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'Ketiga'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEAM_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 2)
        ->assertJsonPath('current', 2);

    $user->forceFill(['max_owned_teams' => 3])->save();

    $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/app/workspaces', ['name' => 'Ketiga'])->assertCreated();

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    teamWorkspace($admin);
    teamWorkspace($admin);
    $this->actingAs($admin, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'Admin Tiga'])->assertCreated();
});

test('members read a workspace; non-members get the generic 403', function (): void {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();
    $profile = LimitProfile::factory()->create(['name' => 'Magang GMEDIA']);
    $team = teamWorkspace($owner, [[$viewer, WorkspaceRole::Viewer]], ['limit_profile_id' => $profile->id]);

    $this->actingAs($viewer, 'web')->getJson("/api/v1/app/workspaces/{$team->id}")
        ->assertOk()
        ->assertJsonPath('data.role', 'viewer')
        ->assertJsonPath('data.limit_profile.name', 'Magang GMEDIA');

    $this->actingAs($stranger, 'web')->getJson("/api/v1/app/workspaces/{$team->id}")
        ->assertForbidden()
        ->assertJsonMissingPath('code');
    $this->actingAs($stranger, 'web')->getJson("/api/v1/app/workspaces/{$team->id}/members")->assertForbidden();
    $this->actingAs($stranger, 'web')->getJson('/api/v1/app/workspaces/'.Str::uuid7())->assertNotFound();
});

test('owners rename a team; members and viewers cannot; a personal workspace cannot be renamed', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]], ['slug' => 'tim-lama']);

    $this->actingAs($member, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}", ['name' => 'Baru'])->assertForbidden();

    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}", ['name' => 'Tim Baru'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Tim Baru')
        ->assertJsonPath('data.slug', 'tim-lama');

    expect(AuditEvent::query()->where('action', 'workspace.renamed')->where('subject_id', $team->id)->exists())->toBeTrue();

    $personal = $owner->personalWorkspace()->firstOrFail();
    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$personal->id}", ['name' => 'Lain'])
        ->assertConflict()
        ->assertJsonPath('code', 'WORKSPACE_IS_PERSONAL');
});

test('a team is deleted only when it has no projects', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]]);
    $project = Project::factory()->inWorkspace($team)->create();
    $trashed = Project::factory()->inWorkspace($team)->create();
    $trashed->delete();

    $this->actingAs($member, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}")->assertForbidden();

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}")
        ->assertConflict()
        ->assertJsonPath('code', 'WORKSPACE_NOT_EMPTY')
        ->assertJsonPath('projects', 1);

    $project->delete();

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}")->assertNoContent();

    expect(Workspace::query()->whereKey($team->id)->exists())->toBeFalse()
        ->and(WorkspaceMember::query()->where('workspace_id', $team->id)->exists())->toBeFalse()
        ->and(Project::withTrashed()->whereKey($trashed->id)->value('workspace_id'))->toBeNull()
        ->and(AuditEvent::query()->where('action', 'workspace.deleted')->where('subject_id', $team->id)->exists())->toBeTrue();

    $personal = $owner->personalWorkspace()->firstOrFail();
    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$personal->id}")
        ->assertConflict()->assertJsonPath('code', 'WORKSPACE_IS_PERSONAL');
});

test('members are listed for every member, with emails for owners only', function (): void {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $team = teamWorkspace($owner, [[$viewer, WorkspaceRole::Viewer]]);

    $this->actingAs($viewer, 'web')->getJson("/api/v1/app/workspaces/{$team->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.0.user.username', $owner->username)
        ->assertJsonMissingPath('data.0.user.email');

    $this->actingAs($owner, 'web')->getJson("/api/v1/app/workspaces/{$team->id}/members")
        ->assertOk()
        ->assertJsonPath('data.1.user.email', $viewer->email);
});

test('owners change roles, and the last owner cannot be demoted', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]]);
    $ownerMembership = $team->members()->where('user_id', $owner->id)->firstOrFail();
    $memberMembership = $team->members()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($member, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$ownerMembership->id}", ['role' => 'viewer'])
        ->assertForbidden();

    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$ownerMembership->id}", ['role' => 'member'])
        ->assertConflict()
        ->assertJsonPath('code', 'LAST_WORKSPACE_OWNER')
        ->assertJsonPath('workspaces.0.id', $team->id);

    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$memberMembership->id}", ['role' => 'owner'])
        ->assertOk()
        ->assertJsonPath('data.role', 'owner');

    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$ownerMembership->id}", ['role' => 'viewer'])
        ->assertOk()
        ->assertJsonPath('data.role', 'viewer');

    $this->actingAs($member, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$memberMembership->id}", ['role' => 'boss'])
        ->assertUnprocessable()->assertJsonValidationErrors('role');

    $audit = AuditEvent::query()->where('action', 'workspace.member_role_changed')->orderBy('id')->firstOrFail();
    expect($audit->metadata)->toBe(['member_user_id' => $member->id, 'from' => 'member', 'to' => 'owner']);
});

test('a member of another workspace cannot be addressed through this one', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    $otherTeam = teamWorkspace(User::factory()->create(), [[User::factory()->create(), WorkspaceRole::Member]]);
    $foreign = $otherTeam->members()->where('role', 'member')->firstOrFail();

    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$foreign->id}", ['role' => 'viewer'])
        ->assertNotFound();
    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}/members/{$foreign->id}")
        ->assertNotFound();
});

test('owners remove members, who lose access at once; the last owner cannot be removed', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]]);
    $project = Project::factory()->inWorkspace($team, $member)->create();
    $memberMembership = $team->members()->where('user_id', $member->id)->firstOrFail();
    $ownerMembership = $team->members()->where('user_id', $owner->id)->firstOrFail();

    $this->actingAs($member, 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertOk();

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}/members/{$ownerMembership->id}")
        ->assertConflict()->assertJsonPath('code', 'LAST_WORKSPACE_OWNER');

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}/members/{$memberMembership->id}")
        ->assertNoContent();

    $this->actingAs($member, 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertForbidden();
    expect(Project::query()->whereKey($project->id)->value('workspace_id'))->toBe($team->id)
        ->and(AuditEvent::query()->where('action', 'workspace.member_removed')->where('subject_id', $team->id)->exists())->toBeTrue();
});

test('members leave a team, the last owner cannot, and nobody leaves a personal workspace', function (): void {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $team = teamWorkspace($owner, [[$viewer, WorkspaceRole::Viewer]]);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/leave")
        ->assertConflict()->assertJsonPath('code', 'LAST_WORKSPACE_OWNER');

    $this->actingAs($viewer, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/leave")->assertNoContent();
    expect($team->roleOf($viewer))->toBeNull()
        ->and(AuditEvent::query()->where('action', 'workspace.member_left')->where('subject_id', $team->id)->exists())->toBeTrue();

    $this->actingAs($viewer, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/leave")->assertForbidden();

    $personal = $owner->personalWorkspace()->firstOrFail();
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$personal->id}/leave")
        ->assertConflict()->assertJsonPath('code', 'WORKSPACE_IS_PERSONAL');
});

test('assigning a limit profile is audited and detaching falls back to the default', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    $profile = LimitProfile::factory()->create();
    $action = app(AssignLimitProfileAction::class);

    $action->handle(null, $team, $profile);
    $action->handle(null, $team, $profile);
    $action->handle(null, $team, null);

    $events = AuditEvent::query()->where('action', 'workspace.limit_profile_changed')->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->metadata)->toBe(['from_limit_profile_id' => null, 'to_limit_profile_id' => $profile->id])
        ->and($events[1]->metadata)->toBe(['from_limit_profile_id' => $profile->id, 'to_limit_profile_id' => null])
        ->and($events[0]->actor_type)->toBe('system')
        ->and($team->refresh()->limit_profile_id)->toBeNull();
});
