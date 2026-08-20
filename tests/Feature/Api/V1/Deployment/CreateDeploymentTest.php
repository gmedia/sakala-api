<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function fakeGithubCommit(
    string $sha = '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d',
    string $message = 'fix: refactor auth handler',
): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            [
                'sha' => $sha,
                'commit' => [
                    'message' => $message,
                ],
            ],
        ], 200),
    ]);
}

test('user can create a deployment', function (): void {
    fakeGithubCommit(
        sha: '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d',
        message: "fix: refactor auth handler\n\nAdditional details",
    );

    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
        );

    $response
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.sequence', 1)
        ->assertJsonPath('data.branch', 'main')
        ->assertJsonPath(
            'data.status',
            DeploymentStatus::Queued->value,
        )
        ->assertJsonPath(
            'data.trigger',
            DeploymentTrigger::Manual->value,
        )
        ->assertJsonPath(
            'data.commit_sha',
            '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d',
        )
        ->assertJsonPath(
            'data.commit_message',
            'fix: refactor auth handler',
        );

    $this->assertDatabaseHas('deployments', [
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'sequence' => 1,
        'branch' => 'main',
        'status' => DeploymentStatus::Queued->value,
        'trigger' => DeploymentTrigger::Manual->value,
        'commit_sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d',
        'commit_message' => 'fix: refactor auth handler',
    ]);

    Queue::assertPushed(
        \App\Jobs\Deployment\SimulatedDeploymentJob::class,
    );
});

test('deployment sequence increments for the same project', function (): void {
    fakeGithubCommit();

    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            ['branch' => 'main'],
        )
        ->assertCreated()
        ->assertJsonPath('data.sequence', 1);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            ['branch' => 'main'],
        )
        ->assertCreated()
        ->assertJsonPath('data.sequence', 2);

    $this->assertDatabaseCount('deployments', 2);

    $this->assertDatabaseHas('deployments', [
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    $this->assertDatabaseHas('deployments', [
        'project_id' => $project->id,
        'sequence' => 2,
    ]);

    Queue::assertPushed(
        \App\Jobs\Deployment\SimulatedDeploymentJob::class,
        2,
    );
});

test('deployment sequence is independent between projects', function (): void {
    fakeGithubCommit();

    Queue::fake();

    $user = User::factory()->create();

    $projectA = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $projectB = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$projectA->id}/deployments",
            ['branch' => 'main'],
        )
        ->assertCreated()
        ->assertJsonPath('data.sequence', 1);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$projectA->id}/deployments",
            ['branch' => 'main'],
        )
        ->assertCreated()
        ->assertJsonPath('data.sequence', 2);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$projectB->id}/deployments",
            ['branch' => 'main'],
        )
        ->assertCreated()
        ->assertJsonPath('data.sequence', 1);

    $this->assertDatabaseHas('deployments', [
        'project_id' => $projectA->id,
        'sequence' => 1,
    ]);

    $this->assertDatabaseHas('deployments', [
        'project_id' => $projectA->id,
        'sequence' => 2,
    ]);

    $this->assertDatabaseHas('deployments', [
        'project_id' => $projectB->id,
        'sequence' => 1,
    ]);
});

test('user cannot create deployment for another user project', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $otherUser->id,
        'branch' => 'main',
    ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
        )
        ->assertForbidden();

    $this->assertDatabaseCount('deployments', 0);
});

test('guest cannot create a deployment', function (): void {
    $project = Project::factory()->create([
        'branch' => 'main',
    ]);

    $this
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
        )
        ->assertUnauthorized();

    $this->assertDatabaseCount('deployments', 0);
});

test('branch is required', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'branch',
        ]);

    $this->assertDatabaseCount('deployments', 0);
});

test('deployment branch must match project branch', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'develop',
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'branch',
        ]);

    $this->assertDatabaseCount('deployments', 0);
});

test('deployment uses latest commit from the selected branch', function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            [
                'sha' => 'abc123456789',
                'commit' => [
                    'message' => "feat: latest deployment\nsecond line",
                ],
            ],
        ], 200),
    ]);

    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
        );

    $response
        ->assertCreated()
        ->assertJsonPath(
            'data.commit_sha',
            'abc123456789',
        )
        ->assertJsonPath(
            'data.commit_message',
            'feat: latest deployment',
        );

    $this->assertDatabaseHas('deployments', [
        'project_id' => $project->id,
        'commit_sha' => 'abc123456789',
        'commit_message' => 'feat: latest deployment',
    ]);
});

test('deployment fails when github branch does not exist', function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([], 404),
    ]);

    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'branch',
        ]);

    $this->assertDatabaseCount('deployments', 0);

    Queue::assertNothingPushed();
});

test('deployment fails when github branch has no commits', function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([], 200),
    ]);

    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'branch',
        ]);

    $this->assertDatabaseCount('deployments', 0);

    Queue::assertNothingPushed();
});

test('deployment fails when github request fails', function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response(
            ['message' => 'Internal Server Error'],
            500,
        ),
    ]);

    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
        )
        ->assertServerError();

    $this->assertDatabaseCount('deployments', 0);

    Queue::assertNothingPushed();
});

test('creating deployment dispatches simulated deployment job', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
    ]);

    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            [
                'sha' => 'abc123456789',
                'commit' => [
                    'message' => 'feat: latest deployment',
                ],
            ],
        ], 200),
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->withHeader('Idempotency-Key', 'deployment-job-test')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            [
                'branch' => 'main',
            ],
        );

    $response->assertCreated();

    $deploymentId = $response->json('data.id');

    Queue::assertPushed(
        SimulatedDeploymentJob::class,
        function (SimulatedDeploymentJob $job) use ($deploymentId): bool {
            return $job->uniqueId() === $deploymentId;
        },
    );
});