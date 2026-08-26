<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\DeploymentStatus;
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

test('repeated deployment creation allocates unique sequential numbers', function (): void {
    Queue::fake();

    config([
        'sakala.pilot_limits.max_active_deployments_per_user' => 10,
        'sakala.pilot_limits.max_active_deployments_per_project' => 10,
    ]);

    $user = User::factory()->create();

    $project = Project::factory()->for($user)->create([
        'branch' => 'main',
    ]);

    $action = app(CreateDeploymentAction::class);

    $deployment1 = $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(
            branch: 'main',
        ),
    );

    $deployment2 = $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(
            branch: 'main',
        ),
    );

    $deployment3 = $action->handle(
        project: $project,
        user: $user,
        data: new CreateDeploymentData(
            branch: 'main',
        ),
    );

    expect($deployment1->sequence)->toBe(1)
        ->and($deployment2->sequence)->toBe(2)
        ->and($deployment3->sequence)->toBe(3);

    expect(
        Deployment::query()
            ->where('project_id', $project->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all()
    )->toBe([1, 2, 3]);
});