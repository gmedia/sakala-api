<?php

declare(strict_types=1);

use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            [
                'sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d',
                'commit' => [
                    'message' => 'test deployment commit',
                ],
            ],
        ], 200),
    ]);
});

test('repeated deployment request with same idempotency key returns the same deployment', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create([
        'branch' => 'main',
    ]);

    $payload = [
        'branch' => 'main',
    ];

    $headers = [
        'Idempotency-Key' => 'deployment-idempotency-001',
    ];

    $first = $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            $payload,
            $headers,
        );

    $first->assertCreated();

    $deploymentId = $first->json('data.id');

    $second = $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            $payload,
            $headers,
        );

    $second->assertCreated();

    expect($second->json('data.id'))
        ->toBe($deploymentId);

    expect(Deployment::query()
        ->where('project_id', $project->id)
        ->count()
    )->toBe(1);

    Queue::assertPushed(
        SimulatedDeploymentJob::class,
        1,
    );
});

test('idempotency key cannot be reused for a different deployment', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create([
        'branch' => 'main',
    ]);

    $headers = [
        'Idempotency-Key' => 'deployment-idempotency-002',
    ];

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
            $headers,
        )
        ->assertCreated();

    /*
     * The project only accepts its configured branch, so this also verifies
     * that the same idempotency key cannot be silently reused for another
     * deployment request.
     */
    $response = $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'develop',
            ],
            $headers,
        );

    $response->assertUnprocessable();

    expect(Deployment::query()
        ->where('project_id', $project->id)
        ->count()
    )->toBe(1);
});

test('different idempotency keys create different deployments', function (): void {
    Queue::fake();

    config([
        'sakala.pilot_limits.max_active_deployments_per_user' => 10,
        'sakala.pilot_limits.max_active_deployments_per_project' => 10,
    ]);

    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create([
        'branch' => 'main',
    ]);

    $first = $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            ['branch' => 'main'],
            ['Idempotency-Key' => 'deployment-key-a'],
        );

    $first->assertCreated();

    $second = $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            ['branch' => 'main'],
            ['Idempotency-Key' => 'deployment-key-b'],
        );

    $second->assertCreated();

    expect($second->json('data.id'))
        ->not->toBe($first->json('data.id'));

    expect(Deployment::query()
        ->where('project_id', $project->id)
        ->count()
    )->toBe(2);

    expect(Deployment::query()
        ->where('project_id', $project->id)
        ->orderBy('sequence')
        ->pluck('sequence')
        ->all()
    )->toBe([1, 2]);
});
