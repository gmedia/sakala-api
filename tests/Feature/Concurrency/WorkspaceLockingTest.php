<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Actions\Project\CreateProjectAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Data\Project\CreateProjectData;
use App\Enums\GithubRepositorySource;
use App\Enums\WorkspaceRole;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\ProjectLimitExceededException;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');

    Queue::fake();
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d', 'commit' => ['message' => 'feat: concurrency']],
        ], 200),
        '*' => Http::response([], 404),
    ]);
});

function teamProjectData(string $workspaceId, string $name): CreateProjectData
{
    return new CreateProjectData(
        name: $name,
        branch: 'main',
        repositorySource: GithubRepositorySource::PublicUrl,
        repositoryUrl: 'https://github.com/gmedia/'.strtolower(str_replace(' ', '-', $name)),
        workspaceId: $workspaceId,
    );
}

test('two members creating projects in one team are serialized by the workspace row lock', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_projects' => 1]);
    $team = teamWorkspace($first, [[$second, WorkspaceRole::Member]], ['limit_profile_id' => $profile->id]);
    $action = app(CreateProjectAction::class);

    // Different users, so only the workspace row can make the second wait.
    whileTransactionHoldsLocks(
        holdLocks: fn () => $action->handle($first, teamProjectData($team->id, 'Pertama')),
        contend: fn () => $action->handle($second, teamProjectData($team->id, 'Kedua')),
    );

    expect(fn () => $action->handle($second, teamProjectData($team->id, 'Kedua')))
        ->toThrow(ProjectLimitExceededException::class);

    expect(Project::query()->where('workspace_id', $team->id)->count())->toBe(1);
});

test('two members deploying different projects of one team are serialized by the workspace row lock', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_active_deployments' => 1, 'max_active_containers' => 10]);
    $team = teamWorkspace($first, [[$second, WorkspaceRole::Member]], ['limit_profile_id' => $profile->id]);
    $projectA = Project::factory()->inWorkspace($team, $first)->create(['branch' => 'main']);
    $projectB = Project::factory()->inWorkspace($team, $second)->create(['branch' => 'main']);
    $action = app(CreateDeploymentAction::class);

    whileTransactionHoldsLocks(
        holdLocks: fn () => $action->handle($projectA, $first, new CreateDeploymentData(branch: 'main')),
        contend: fn () => $action->handle($projectB, $second, new CreateDeploymentData(branch: 'main')),
    );

    expect(fn () => $action->handle($projectB, $second, new CreateDeploymentData(branch: 'main')))
        ->toThrow(ActiveDeploymentLimitExceededException::class);

    expect(Deployment::query()->count())->toBe(1);
});
