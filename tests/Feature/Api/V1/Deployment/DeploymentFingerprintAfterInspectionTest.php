<?php

declare(strict_types=1);

use App\Enums\AgentCommandType;
use App\Enums\DeploymentBuildMode;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Support\Project\DeploymentConfigFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Ledger X-36: a redeploy pressed right after a root directory change is
 * created before the inspection of the new directory lands, so it stores the
 * fallback build fingerprint (every variable counts). Once the inspection
 * finds a plain server, that deployment gets the plain-server fingerprint, so
 * a later runtime-only variable change reuses its image, also through a
 * rollback to it.
 */

uses(RefreshDatabase::class);

/** The branch head GitHub answers for every deployment and inspection here. */
const X36_HEAD = '9e1f0c2b3a4d5e6f708192a3b4c5d6e7f8091a2b';

beforeEach(function (): void {
    Mail::fake();
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => X36_HEAD, 'commit' => ['message' => 'feat: express server']],
        ], 200),
    ]);
    config(['sakala.deployments.inspection_wait_seconds' => 900]);

    $this->node = rollbackNode([...ROLLBACK_CAPABILITIES, 'project-inspection', 'railpack-info']);
});

/**
 * A team project on `main`, inspected as a static site in `static-site`, with
 * no configured port and one variable.
 *
 * @return array{owner: User, project: Project}
 */
function x36StaticProject(): array
{
    ['team' => $team, 'project' => $project] = rollbackProject(['SITE_TITLE' => 'one'], [
        ...inspectedProjectAttributes([...railpackStackInspection('staticfile'), 'root_directory' => 'static-site']),
        'root_directory' => 'static-site',
        'build_command' => null,
        'start_command' => null,
        'release_command' => null,
        'configured_port' => null,
        'auto_deploy' => false,
    ]);

    return ['owner' => $team['owner'], 'project' => $project];
}

function x36Deploy(Project $project, User $user, array $body = []): Deployment
{
    $id = test()->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main', ...$body])
        ->assertCreated()
        ->json('data.id');

    return Deployment::query()->findOrFail($id);
}

/** What the Console does on a root directory change: save it, then ask for an inspection. */
function x36ChangeRoot(Project $project, User $user, string $rootDirectory): AgentCommand
{
    test()->actingAs($user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['root_directory' => $rootDirectory])
        ->assertOk();
    test()->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/inspection")
        ->assertSuccessful();

    return AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::InspectProject)
        ->latest('created_at')
        ->firstOrFail();
}

/** @param  array<string, mixed>  $inspection */
function x36CompleteInspection(AgentNode $node, AgentCommand $inspection, array $inspectionResult): void
{
    test()->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$inspection->id}/claim", [])->assertOk();
    test()->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$inspection->id}/complete", ['result' => [...$inspectionResult, 'commit_sha' => X36_HEAD]])
        ->assertNoContent();
}

function x36AddVariable(Project $project, User $user, string $key): void
{
    test()->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/environment-variables", ['key' => $key, 'value' => 'v'])
        ->assertCreated();
}

function x36ProjectBuildFingerprint(Project $project): string
{
    $project = $project->fresh();

    return DeploymentConfigFingerprint::forBuild($project, $project->environmentVariables);
}

test('a redeploy right after a root change gets the plain-server fingerprint once the inspection lands, so a later runtime variable change reuses its image, also after a rollback', function (): void {
    ['owner' => $owner, 'project' => $project] = x36StaticProject();
    $first = rollbackSucceed(x36Deploy($project, $owner));

    $inspection = x36ChangeRoot($project, $owner, 'node-express');
    $afterRootChange = x36Deploy($project, $owner);
    $fallback = $afterRootChange->build_fingerprint;

    // Created before the stack of the new directory was known: every variable counts.
    expect($afterRootChange->awaiting_inspection_until)->not->toBeNull()
        ->and($afterRootChange->build_mode)->toBe(DeploymentBuildMode::Built)
        ->and($fallback)->toBe(DeploymentConfigFingerprint::forBuildWithoutInspection($project->fresh(), $project->fresh()->environmentVariables));

    x36CompleteInspection($this->node, $inspection, railpackStackInspection('express'));

    $afterRootChange->refresh();
    expect($afterRootChange->awaiting_inspection_until)->toBeNull()
        ->and($afterRootChange->build_fingerprint)->not->toBe($fallback)
        ->and($afterRootChange->build_fingerprint)->toBe(x36ProjectBuildFingerprint($project))
        // The first deployment (the other directory) keeps its fingerprint.
        ->and($first->fresh()->build_fingerprint)->toBe($first->build_fingerprint);

    rollbackSucceed($afterRootChange);
    rollbackSucceed(x36Deploy($project, $owner, ['force_build' => true]));

    // The rollback inherits the corrected fingerprint and reuses the image.
    $rollbackId = $this->actingAs($owner, 'web')
        ->postJson(rollbackUrl($project, $afterRootChange))
        ->assertCreated()
        ->json('data.id');
    $rollback = rollbackSucceed(Deployment::query()->findOrFail($rollbackId));

    expect($rollback->build_mode)->toBe(DeploymentBuildMode::Reused)
        ->and($rollback->image_source_deployment_id)->toBe($afterRootChange->id)
        ->and($rollback->build_fingerprint)->toBe($afterRootChange->fresh()->build_fingerprint);

    // A runtime-only variable on a plain server: no build.
    x36AddVariable($project, $owner, 'API_TIMEOUT');
    $runtimeRedeploy = x36Deploy($project, $owner);

    expect($runtimeRedeploy->build_mode)->toBe(DeploymentBuildMode::Reused)
        ->and($runtimeRedeploy->image_source_deployment_id)->toBe($afterRootChange->id);
});

test('a static site keeps building on every variable change after the inspection lands', function (): void {
    ['owner' => $owner, 'project' => $project] = x36StaticProject();
    rollbackSucceed(x36Deploy($project, $owner));

    $inspection = x36ChangeRoot($project, $owner, 'docs-site');
    $afterRootChange = x36Deploy($project, $owner);
    $fallback = $afterRootChange->build_fingerprint;

    x36CompleteInspection($this->node, $inspection, railpackStackInspection('staticfile'));

    expect($afterRootChange->fresh()->build_fingerprint)->toBe($fallback);
    rollbackSucceed($afterRootChange);

    x36AddVariable($project, $owner, 'API_TIMEOUT');

    expect(x36Deploy($project, $owner)->build_mode)->toBe(DeploymentBuildMode::Built);
});

test('a deployment whose inputs differ from the inspected ones keeps its fallback fingerprint', function (string $difference): void {
    ['owner' => $owner, 'project' => $project] = x36StaticProject();
    rollbackSucceed(x36Deploy($project, $owner));

    $inspection = x36ChangeRoot($project, $owner, 'node-express');
    $afterRootChange = x36Deploy($project, $owner);
    $fallback = $afterRootChange->build_fingerprint;

    match ($difference) {
        // Changed after the click: the image was built with the old value.
        'variable changed since' => x36AddVariable($project, $owner, 'API_TIMEOUT'),
        // Built from another commit than the one inspected.
        'another commit' => $afterRootChange->update(['commit_sha' => str_repeat('a', 40)]),
    };

    x36CompleteInspection($this->node, $inspection, railpackStackInspection('express'));

    expect($afterRootChange->fresh()->build_fingerprint)->toBe($fallback);
})->with(['variable changed since', 'another commit']);
