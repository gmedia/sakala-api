<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can view pilot limits and current quota usage', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Deployment::factory()->for($project)->for($user, 'requester')->create([
        'status' => DeploymentStatus::Building,
    ]);

    $this->actingAs($user, 'web');

    $response = $this->getJson('/api/v1/app/pilot-limits');

    $response
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'quotas' => [
                    'max_projects_per_user',
                    'max_active_deployments_per_user',
                    'max_active_deployments_per_project',
                    'current_projects_count',
                    'current_active_deployments_count',
                ],
                'runtime_defaults' => [
                    'memory_mb',
                    'cpu_millis',
                    'pids_limit',
                ],
                'runtime_maximums' => [
                    'memory_mb',
                    'cpu_millis',
                    'pids_limit',
                ],
                'timeouts' => [
                    'build_timeout_seconds',
                    'start_timeout_seconds',
                    'command_timeout_seconds',
                ],
                'log_bounds' => [
                    'max_line_length',
                    'max_batch_lines',
                    'max_total_bytes',
                ],
            ],
        ])
        ->assertJsonPath('data.quotas.current_projects_count', 1)
        ->assertJsonPath('data.quotas.current_active_deployments_count', 1);
});

test('guest cannot access pilot limits endpoint', function () {
    $this->getJson('/api/v1/app/pilot-limits')
        ->assertUnauthorized();
});
