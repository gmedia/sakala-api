<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\UserRole;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\ResourceLimitExceededException;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('create deployment action resolves effective runtime limits and persists snapshot on deployment', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create([
        'repository_url' => 'https://github.com/example/demo-app',
        'branch' => 'main',
        'default_domain' => 'demo.run.sakala.dev',
    ]);

    $action = app(CreateDeploymentAction::class);

    $deployment = $action->handle($user, $project);

    expect($deployment->requested_resources)->toBeNull()
        ->and($deployment->effective_resources)->toBe([
            'memory_mb' => 256,
            'cpu_millis' => 500,
            'pids_limit' => 128,
        ])
        ->and($deployment->status)->toBe(DeploymentStatus::Queued);

    // Verify AgentCommand payload
    $command = AgentCommand::where('deployment_id', $deployment->id)->first();
    expect($command)->not->toBeNull()
        ->and($command->type)->toBe(AgentCommandType::DeployProject)
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($command->payload['resources'])->toBe([
            'memory_mb' => 256,
            'cpu_millis' => 500,
            'pids_limit' => 128,
        ])
        ->and($command->payload['timeouts'])->toBe([
            'build_timeout_seconds' => 600,
            'start_timeout_seconds' => 120,
            'command_timeout_seconds' => 900,
        ]);
});

test('create deployment action stores custom requested resources when within pilot maximums', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create();

    $action = app(CreateDeploymentAction::class);

    $requested = new RuntimeResourceLimitsData(
        memory_mb: 512,
        cpu_millis: 750,
        pids_limit: 200,
    );

    $deployment = $action->handle($user, $project, new CreateDeploymentData(
        requested_resources: $requested,
    ));

    expect($deployment->requested_resources)->toBe([
        'memory_mb' => 512,
        'cpu_millis' => 750,
        'pids_limit' => 200,
    ])->and($deployment->effective_resources)->toBe([
        'memory_mb' => 512,
        'cpu_millis' => 750,
        'pids_limit' => 200,
    ]);
});

test('create deployment action fails when requested resources exceed pilot maximums', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create();

    $action = app(CreateDeploymentAction::class);

    $requested = new RuntimeResourceLimitsData(
        memory_mb: 1024, // Exceeds default max 512MB
    );

    expect(fn () => $action->handle($user, $project, new CreateDeploymentData(
        requested_resources: $requested,
    )))->toThrow(ResourceLimitExceededException::class);
});

test('create deployment action prevents exceeding active deployments per project', function () {
    config(['sakala.pilot_limits.max_active_deployments_per_project' => 1]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create();

    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Building,
    ]);

    $action = app(CreateDeploymentAction::class);

    expect(fn () => $action->handle($user, $project))
        ->toThrow(ActiveDeploymentLimitExceededException::class);
});

test('create deployment action prevents exceeding active deployments per user across projects', function () {
    config([
        'sakala.pilot_limits.max_active_deployments_per_user' => 2,
        'sakala.pilot_limits.max_active_deployments_per_project' => 1,
    ]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $project1 = Project::factory()->for($user)->create();
    $project2 = Project::factory()->for($user)->create();
    $project3 = Project::factory()->for($user)->create();

    Deployment::factory()->for($project1)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Building,
    ]);
    Deployment::factory()->for($project2)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Deploying,
    ]);

    $action = app(CreateDeploymentAction::class);

    // Attempting deployment on project 3 exceeds user limit of 2 active deployments
    expect(fn () => $action->handle($user, $project3))
        ->toThrow(ActiveDeploymentLimitExceededException::class);
});

test('terminal deployments do not count against active deployment limit', function () {
    config(['sakala.pilot_limits.max_active_deployments_per_project' => 1]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create();

    // Previous deployments reached terminal states
    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Succeeded,
    ]);
    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Failed,
    ]);

    $action = app(CreateDeploymentAction::class);

    $deployment = $action->handle($user, $project);

    expect($deployment)->not->toBeNull()
        ->and($deployment->status)->toBe(DeploymentStatus::Queued);
});
