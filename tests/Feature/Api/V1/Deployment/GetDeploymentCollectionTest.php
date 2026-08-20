<?php

declare(strict_types=1);

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('project owner can list deployments', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    Deployment::factory()->count(3)->create([
        'project_id' => $project->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments"
        );

    $response
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('deployment collection only contains deployments from requested project', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $anotherProject = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    Deployment::factory()->count(2)->create([
        'project_id' => $project->id,
    ]);

    Deployment::factory()->count(3)->create([
        'project_id' => $anotherProject->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments"
        );

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(
        collect($response->json('data'))
            ->every(
                fn (array $deployment): bool => $deployment['project_id'] === $project->id
            )
    )->toBeTrue();
});

test('user cannot list another users deployments', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $owner->id,
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
    ]);

    $response = $this
        ->actingAs($otherUser)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments"
        );

    $response->assertForbidden();
});
