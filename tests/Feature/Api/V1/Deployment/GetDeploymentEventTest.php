<?php

declare(strict_types=1);

use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('project owner can view deployment events', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentEvent::factory()->count(3)->create([
        'deployment_id' => $deployment->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/events"
        );

    $response
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user cannot view events of another users deployment', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $owner->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
    ]);

    $response = $this
        ->actingAs($otherUser)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/events"
        );

    $response->assertForbidden();
});

test('deployment events only belong to requested deployment', function (): void {
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

    DeploymentEvent::factory()->count(2)->create([
        'deployment_id' => $deployment->id,
    ]);

    DeploymentEvent::factory()->count(5)->create([
        'deployment_id' => $anotherDeployment->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/events"
        );

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
