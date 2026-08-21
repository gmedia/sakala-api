<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Enums\DeploymentStatus;
use App\Enums\UserRole;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\ResourceLimitExceededException;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            [
                'sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d',
                'commit' => [
                    'message' => 'fix: pilot runtime test commit',
                ],
            ],
        ], 200),
    ]);
});

use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

test('create deployment action resolves effective runtime limits and persists snapshot on deployment', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create([
        'repository_url' => 'https://github.com/example/demo-app',
        'branch' => 'main',
        'default_domain' => 'demo.run.sakala.dev',
    ]);

    $action = app(CreateDeploymentAction::class);

    $deployment = $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    );

    expect($deployment->requested_resources)->toBeNull()
        ->and($deployment->effective_resources)->toBe([
            'resources' => [
                'memory_mb' => 256,
                'cpu_millis' => 500,
                'pids_limit' => 128,
            ],
            'timeouts' => [
                'build_timeout_seconds' => 600,
                'start_timeout_seconds' => 120,
                'command_timeout_seconds' => 900,
            ],
            'log_bounds' => [
                'max_line_length' => 4096,
                'max_batch_lines' => 500,
                'max_total_bytes' => 10485760,
            ],
        ])
        ->and($deployment->status)->toBe(DeploymentStatus::Queued);

    $this->assertDatabaseHas('agent_commands', [
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'type' => AgentCommandType::DeployProject->value,
    ]);

    $command = AgentCommand::where('deployment_id', $deployment->id)->first();
    expect($command->payload['resources'])->toBe([
        'memory_mb' => 256,
        'cpu_millis' => 500,
        'pids_limit' => 128,
    ])
        ->and($command->payload['timeouts'])->toBe([
            'build_timeout_seconds' => 600,
            'start_timeout_seconds' => 120,
            'command_timeout_seconds' => 900,
        ])
        ->and($command->payload['log_bounds'])->toBe([
            'max_line_length' => 4096,
            'max_batch_lines' => 500,
            'max_total_bytes' => 10485760,
        ]);

    Queue::assertPushed(SimulatedDeploymentJob::class);
});

test('create deployment action stores custom requested resources when within pilot maximums', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $action = app(CreateDeploymentAction::class);

    $requested = new RuntimeResourceLimitsData(
        memory_mb: 512,
        cpu_millis: 750,
        pids_limit: 200,
    );

    $deployment = $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(
            branch: 'main',
            requested_resources: $requested,
        ),
    );

    expect($deployment->requested_resources)->toBe([
        'memory_mb' => 512,
        'cpu_millis' => 750,
        'pids_limit' => 200,
    ])->and($deployment->effective_resources['resources'])->toBe([
        'memory_mb' => 512,
        'cpu_millis' => 750,
        'pids_limit' => 200,
    ]);
});

test('create deployment action fails when requested resources exceed pilot maximums', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $action = app(CreateDeploymentAction::class);

    $requested = new RuntimeResourceLimitsData(
        memory_mb: 1024, // Exceeds default max 512MB
    );

    expect(fn () => $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(
            branch: 'main',
            requested_resources: $requested,
        ),
    ))->toThrow(ResourceLimitExceededException::class);
});

test('create deployment action prevents exceeding active deployments per project', function () {
    config(['sakala.pilot_limits.max_active_deployments_per_project' => 1]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Building,
    ]);

    $action = app(CreateDeploymentAction::class);

    expect(fn () => $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    ))->toThrow(ActiveDeploymentLimitExceededException::class);
});

test('create deployment action prevents exceeding active deployments per user across projects', function () {
    config([
        'sakala.pilot_limits.max_active_deployments_per_user' => 2,
        'sakala.pilot_limits.max_active_deployments_per_project' => 1,
    ]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $project1 = Project::factory()->for($user)->create(['branch' => 'main']);
    $project2 = Project::factory()->for($user)->create(['branch' => 'main']);
    $project3 = Project::factory()->for($user)->create(['branch' => 'main']);

    Deployment::factory()->for($project1)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Building,
    ]);
    Deployment::factory()->for($project2)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Deploying,
    ]);

    $action = app(CreateDeploymentAction::class);

    // Attempting deployment on project 3 exceeds user limit of 2 active deployments
    expect(fn () => $action->handle(
        project: $project3,
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    ))->toThrow(ActiveDeploymentLimitExceededException::class);
});

test('create deployment action includes encrypted environment variables in agent command payload without leaking plaintext at rest', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create([
        'repository_url' => 'https://github.com/example/secure-app',
        'branch' => 'main',
    ]);

    EnvironmentVariable::create([
        'project_id' => $project->id,
        'key' => 'APP_ENV',
        'encrypted_value' => 'production',
        'is_secret' => false,
    ]);

    EnvironmentVariable::create([
        'project_id' => $project->id,
        'key' => 'DEMO_SECRET_KEY',
        'encrypted_value' => 'my-super-secret-token-999',
        'is_secret' => true,
    ]);

    $action = app(CreateDeploymentAction::class);

    $deployment = $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    );

    $command = AgentCommand::where('deployment_id', $deployment->id)->firstOrFail();

    // Verify command payload contains environment keys
    expect($command->payload)->toHaveKey('environment')
        ->and($command->payload['environment'])->toHaveKeys(['APP_ENV', 'DEMO_SECRET_KEY']);

    // Ensure raw database column does NOT contain plaintext secret value
    $rawPayloadJson = DB::table('agent_commands')->where('id', $command->id)->value('payload');
    expect($rawPayloadJson)->not->toContain('my-super-secret-token-999');

    // Ensure stored values are valid ciphertexts that can be safely decrypted
    $decryptedSecret = Crypt::decryptString($command->payload['environment']['DEMO_SECRET_KEY']);
    $decryptedEnv = Crypt::decryptString($command->payload['environment']['APP_ENV']);

    expect($decryptedSecret)->toBe('my-super-secret-token-999')
        ->and($decryptedEnv)->toBe('production');
});

test('create deployment action atomically prevents concurrent active deployment limit races across multiple projects for same user', function () {
    Queue::fake();

    config([
        'sakala.pilot_limits.max_active_deployments_per_user' => 1,
        'sakala.pilot_limits.max_active_deployments_per_project' => 1,
    ]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $projectA = Project::factory()->for($user)->create(['branch' => 'main']);
    $projectB = Project::factory()->for($user)->create(['branch' => 'main']);

    $action = app(CreateDeploymentAction::class);

    // Simulate concurrent requests on project A and project B for the same user
    // First deployment succeeds and acquires active status (Queued)
    $deploymentA = $action->handle(
        project: $projectA,
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    );

    expect($deploymentA->status->isActive())->toBeTrue();

    // Second deployment on a different project (Project B) is serialized under the user lock
    // and must be blocked because the user has already reached max_active_deployments_per_user (1)
    expect(fn () => $action->handle(
        project: $projectB,
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    ))->toThrow(function (ActiveDeploymentLimitExceededException $e) {
        expect($e->scope)->toBe('user')
            ->and($e->limit)->toBe(1)
            ->and($e->current)->toBe(1);
    });

    // Total active deployments remains strictly 1
    $activeCount = Deployment::whereHas('project', fn ($q) => $q->where('user_id', $user->id))
        ->active()
        ->count();

    expect($activeCount)->toBe(1);
});

test('terminal deployments do not count against active deployment limit', function () {
    Queue::fake();
    config(['sakala.pilot_limits.max_active_deployments_per_project' => 1]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    // Previous deployments reached terminal states
    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Succeeded,
    ]);
    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Failed,
    ]);

    $action = app(CreateDeploymentAction::class);

    $deployment = $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    );

    expect($deployment)->not->toBeNull()
        ->and($deployment->status)->toBe(DeploymentStatus::Queued);
});

test('api endpoint stores requested resources and returns effective resources in response', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $response = $this
        ->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", [
            'branch' => 'main',
            'resources' => [
                'memory_mb' => 512,
                'cpu_millis' => 750,
                'pids_limit' => 200,
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.requested_resources.memory_mb', 512)
        ->assertJsonPath('data.requested_resources.cpu_millis', 750)
        ->assertJsonPath('data.requested_resources.pids_limit', 200)
        ->assertJsonPath('data.effective_resources.resources.memory_mb', 512)
        ->assertJsonPath('data.effective_resources.resources.cpu_millis', 750)
        ->assertJsonPath('data.effective_resources.resources.pids_limit', 200)
        ->assertJsonPath('data.effective_resources.timeouts.build_timeout_seconds', 600)
        ->assertJsonPath('data.effective_resources.log_bounds.max_line_length', 4096);
});

test('api endpoint returns 422 with structured code when active deployment limit is exceeded', function () {
    config(['sakala.pilot_limits.max_active_deployments_per_project' => 1]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Deploying,
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", [
            'branch' => 'main',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'code' => 'ACTIVE_DEPLOYMENT_LIMIT_EXCEEDED',
            'scope' => 'project',
            'limit' => 1,
            'current' => 1,
        ]);
});

test('api endpoint returns 422 with structured code when requested resource exceeds pilot limits', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $response = $this
        ->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", [
            'branch' => 'main',
            'resources' => [
                'memory_mb' => 1024,
            ],
        ]);

    $response
        ->assertUnprocessable()
        ->assertJson([
            'code' => 'RESOURCE_LIMIT_EXCEEDED',
            'resource' => 'memory_mb',
            'requested' => 1024,
            'maximum' => 512,
        ]);
});
