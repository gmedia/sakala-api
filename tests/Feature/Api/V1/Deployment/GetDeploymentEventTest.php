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

test('deployment events support cursor pagination in sequence order', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 1,
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 2,
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 3,
    ]);

    $firstResponse = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/events?per_page=2"
        );

    $firstResponse
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.sequence', 1)
        ->assertJsonPath('data.1.sequence', 2)
        ->assertJsonPath('meta.per_page', 2);

    $cursor = $firstResponse->json('meta.next_cursor');

    expect($cursor)->not->toBeNull();

    $secondResponse = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/events"
            .'?per_page=2&cursor='.urlencode($cursor)
        );

    $secondResponse
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sequence', 3);
});

test('deployment events do not expose internal id', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 1,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/events"
        );

    $response
        ->assertOk()
        ->assertJsonMissingPath('data.0.id');
});

test('deployment events are returned in sequence order', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 3,
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 1,
    ]);

    DeploymentEvent::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 2,
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/events"
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.0.sequence', 1)
        ->assertJsonPath('data.1.sequence', 2)
        ->assertJsonPath('data.2.sequence', 3);
});
