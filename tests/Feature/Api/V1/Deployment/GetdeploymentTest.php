<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('project owner can view deployment', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'sequence' => 1,
        'status' => DeploymentStatus::Succeeded,
        'trigger' => DeploymentTrigger::Manual,
        'branch' => 'main',
        'commit_sha' => str_repeat('a', 40),
        'commit_message' => 'feat: latest deployment',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}"
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $deployment->id)
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.sequence', 1)
        ->assertJsonPath('data.branch', 'main')
        ->assertJsonPath('data.status', DeploymentStatus::Succeeded->value)
        ->assertJsonPath('data.trigger', DeploymentTrigger::Manual->value)
        ->assertJsonPath('data.commit_sha', str_repeat('a', 40))
        ->assertJsonPath('data.commit_message', 'feat: latest deployment');
});

test('user cannot view another users deployment', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $owner->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $owner->id,
        'sequence' => 1,
    ]);

    $response = $this
        ->actingAs($otherUser)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}"
        );

    $response->assertForbidden();
});

test('admin can view another users deployment', function (): void {
    $owner = User::factory()->create();

    $admin = User::factory()->create([
        // sesuaikan dengan factory/atribut admin di project kamu
        'role' => 'admin',
    ]);

    $project = Project::factory()->create([
        'user_id' => $owner->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $owner->id,
        'sequence' => 1,
    ]);

    $response = $this
        ->actingAs($admin)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}"
        );

    $response->assertOk();
});

test('deployment from another project cannot be accessed through nested route', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $anotherProject = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $anotherProject->id,
        'sequence' => 1,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}"
        );

    $response->assertNotFound();
});