<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\WorkspaceRole;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('workspaces show their project and active deployment counts', function (): void {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $team = teamWorkspace($owner, [[$viewer, WorkspaceRole::Viewer]]);
    $busy = Project::factory()->inWorkspace($team)->create();
    $idle = Project::factory()->inWorkspace($team)->create();
    Project::factory()->inWorkspace($team)->create()->delete();
    Deployment::factory()->for($busy)->create(['status' => DeploymentStatus::Building]);
    Deployment::factory()->for($idle)->create(['status' => DeploymentStatus::Queued]);
    Deployment::factory()->for($idle)->create(['status' => DeploymentStatus::Succeeded]);
    Deployment::factory()->for($busy)->create(['status' => DeploymentStatus::Failed]);
    // Someone else's workspace counts nothing here.
    Deployment::factory()->create(['status' => DeploymentStatus::Building]);
    $personal = $owner->personalWorkspace()->firstOrFail();
    Project::factory()->inWorkspace($personal)->create();

    $this->actingAs($viewer, 'web')->getJson("/api/v1/app/workspaces/{$team->id}")
        ->assertOk()
        ->assertJsonPath('data.projects_count', 2)
        ->assertJsonPath('data.active_deployments_count', 2);

    $this->actingAs($owner, 'web')->getJson('/api/v1/app/workspaces')
        ->assertOk()
        ->assertJsonPath('data.0.id', $personal->id)
        ->assertJsonPath('data.0.projects_count', 1)
        ->assertJsonPath('data.0.active_deployments_count', 0)
        ->assertJsonPath('data.1.id', $team->id)
        ->assertJsonPath('data.1.projects_count', 2)
        ->assertJsonPath('data.1.active_deployments_count', 2);
});

test('the counts come with every workspace response', function (): void {
    config(['sakala.email_delivery_enabled' => true]);
    Notification::fake();
    $owner = User::factory()->create();

    $created = $this->actingAs($owner, 'web')->postJson('/api/v1/app/workspaces', ['name' => 'Tim Baru'])
        ->assertCreated()
        ->assertJsonPath('data.projects_count', 0)
        ->assertJsonPath('data.active_deployments_count', 0)
        ->json('data.id');

    $team = $owner->workspaces()->whereKey($created)->firstOrFail();
    Project::factory()->inWorkspace($team)->create();

    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$created}", ['name' => 'Tim Lama'])
        ->assertOk()
        ->assertJsonPath('data.projects_count', 1);

    $invitee = User::factory()->create(['email' => 'in@example.test']);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$created}/invitations", ['email' => 'in@example.test', 'role' => 'member']);
    $invitation = WorkspaceInvitation::query()->firstOrFail();

    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.projects_count', 1)
        ->assertJsonPath('data.active_deployments_count', 0);
});

test('listing workspaces costs the same number of queries however many there are', function (): void {
    $user = User::factory()->create();
    $queriesFor = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user, 'web')->getJson('/api/v1/app/workspaces')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $team = teamWorkspace($user);
    Deployment::factory()->for(Project::factory()->inWorkspace($team))->create();
    $one = $queriesFor();

    foreach (range(1, 4) as $i) {
        $other = teamWorkspace(User::factory()->create(), [[$user, WorkspaceRole::Member]]);
        Deployment::factory()->for(Project::factory()->inWorkspace($other))->create();
    }

    expect($queriesFor())->toBe($one);
});
