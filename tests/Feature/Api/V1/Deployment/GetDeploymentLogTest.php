<?php

declare(strict_types=1);

use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('project owner can view deployment logs', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->count(3)->create([
        'deployment_id' => $deployment->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user cannot view logs of another users deployment', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $owner->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
    ]);

    $response = $this
        ->actingAs($otherUser)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response->assertForbidden();
});

test('deployment logs only belong to requested deployment', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    $anotherDeployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 2,
    ]);

    DeploymentLog::factory()->count(2)->create([
        'deployment_id' => $deployment->id,
    ]);

    DeploymentLog::factory()->count(5)->create([
        'deployment_id' => $anotherDeployment->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
