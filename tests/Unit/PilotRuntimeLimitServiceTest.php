<?php

declare(strict_types=1);

use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Enums\DeploymentStatus;
use App\Enums\UserRole;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\ProjectLimitExceededException;
use App\Exceptions\Runtime\ResourceLimitExceededException;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new PilotRuntimeLimitService;
});

test('it resolves default runtime limits when no requested resources provided', function () {
    $effective = $this->service->resolveEffectiveLimits();

    expect($effective->memory_mb)->toBe(256)
        ->and($effective->cpu_millis)->toBe(500)
        ->and($effective->pids_limit)->toBe(128)
        ->and($effective->timeouts->build_timeout_seconds)->toBe(600)
        ->and($effective->timeouts->start_timeout_seconds)->toBe(120)
        ->and($effective->timeouts->command_timeout_seconds)->toBe(900)
        ->and($effective->log_bounds->max_line_length)->toBe(4096)
        ->and($effective->log_bounds->max_batch_lines)->toBe(500);
});

test('it accepts valid requested resource limits within pilot maximums', function () {
    $requested = new RuntimeResourceLimitsData(
        memory_mb: 512,
        cpu_millis: 1000,
        pids_limit: 256,
    );

    $effective = $this->service->resolveEffectiveLimits($requested);

    expect($effective->memory_mb)->toBe(512)
        ->and($effective->cpu_millis)->toBe(1000)
        ->and($effective->pids_limit)->toBe(256);
});

test('it rejects requested memory exceeding max limit', function () {
    $requested = new RuntimeResourceLimitsData(memory_mb: 1024);

    $this->service->resolveEffectiveLimits($requested);
})->throws(ResourceLimitExceededException::class);

test('it rejects requested cpu exceeding max limit', function () {
    $requested = new RuntimeResourceLimitsData(cpu_millis: 2000);

    $this->service->resolveEffectiveLimits($requested);
})->throws(ResourceLimitExceededException::class);

test('it rejects requested pids exceeding max limit', function () {
    $requested = new RuntimeResourceLimitsData(pids_limit: 512);

    $this->service->resolveEffectiveLimits($requested);
})->throws(ResourceLimitExceededException::class);

test('it rejects zero or negative requested limits', function () {
    $requested = new RuntimeResourceLimitsData(memory_mb: 0);

    $this->service->resolveEffectiveLimits($requested);
})->throws(ResourceLimitExceededException::class);

test('it allows project creation within quota and throws when exceeded', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    config(['sakala.pilot_limits.max_projects_per_user' => 2]);

    // 0 projects: allowed
    $this->service->checkProjectCreationLimit($user);

    // Create 1 project: allowed
    Project::factory()->for($user)->create();
    $this->service->checkProjectCreationLimit($user);

    // Create 2nd project: at limit, next attempt throws
    Project::factory()->for($user)->create();

    expect(fn () => $this->service->checkProjectCreationLimit($user))
        ->toThrow(ProjectLimitExceededException::class);
});

test('it allows active deployments within quota and throws when exceeded', function () {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create();

    config([
        'sakala.pilot_limits.max_active_deployments_per_user' => 2,
        'sakala.pilot_limits.max_active_deployments_per_project' => 1,
    ]);

    // 0 deployments: allowed
    $this->service->checkActiveDeploymentLimit($user, $project);

    // Create 1 active deployment for this project: project limit reached
    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Building,
    ]);

    expect(fn () => $this->service->checkActiveDeploymentLimit($user, $project))
        ->toThrow(ActiveDeploymentLimitExceededException::class);
});

test('it allows admin to bypass project quota and active deployment limits', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $project = Project::factory()->for($admin)->create();

    config([
        'sakala.pilot_limits.max_projects_per_user' => 1,
        'sakala.pilot_limits.max_active_deployments_per_project' => 1,
    ]);

    Project::factory()->for($admin)->count(3)->create();
    Deployment::factory()->for($project)->for($admin, 'requester')->count(2)->create([
        'status' => DeploymentStatus::Deploying,
    ]);

    // Admin should not throw
    $this->service->checkProjectCreationLimit($admin);
    $this->service->checkActiveDeploymentLimit($admin, $project);

    expect(true)->toBeTrue();
});
