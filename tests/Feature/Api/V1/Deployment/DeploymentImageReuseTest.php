<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentBuildFallbackReason;
use App\Enums\DeploymentBuildMode;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\DatabaseAttachment;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/** The branch head GitHub answers for every manual deployment in this file. */
const REUSE_BRANCH_HEAD = '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d';

/** A commit older than the branch head. */
const REUSE_OLDER_COMMIT = '7d0c5b1a9e8f7a6b5c4d3e2f1a0b9c8d7e6f5a4b';

/** What a current docker-driver agent advertises. */
const REUSE_CAPABILITIES = ['docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', 'release-command', AgentCommandType::MANAGED_POSTGRES_CAPABILITY, AgentNode::IMAGE_REUSE_CAPABILITY];

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => REUSE_BRANCH_HEAD, 'commit' => ['message' => 'feat: live version']],
        ], 200),
    ]);
});

/** One node that runs projects and hosts databases, advertising `$capabilities`. */
function reuseNode(array $capabilities = REUSE_CAPABILITIES, array $overrides = []): AgentNode
{
    return databaseNode(overrides: [
        'capabilities' => $capabilities,
        'metadata' => [
            'workloads' => [],
            'database' => ['endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT]],
        ],
        ...$overrides,
    ]);
}

/**
 * A team (profile overrides in `$profile`) with a project on `main` and the
 * given variables.
 *
 * @param  array<string, string>  $variables
 * @return array{team: array{workspace: Workspace, owner: User, member: User, viewer: User, outsider: User, admin: User}, project: Project}
 */
function reuseProject(array $variables = [], array $projectAttributes = [], array $profile = []): array
{
    $team = databaseTeam($profile);
    $project = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create(['branch' => 'main', ...$projectAttributes]);

    foreach ($variables as $key => $value) {
        EnvironmentVariable::query()->create(['project_id' => $project->id, 'key' => $key, 'encrypted_value' => $value, 'is_secret' => true]);
    }

    return ['team' => $team, 'project' => $project];
}

/** A deployment through the real action, as `$user`. */
function reuseDeploy(
    Project $project,
    User $user,
    bool $forceBuild = false,
    ?string $commit = null,
    DeploymentTrigger $trigger = DeploymentTrigger::Manual,
): Deployment {
    return app(CreateDeploymentAction::class)->handle(
        project: $project->fresh(),
        user: $user,
        data: new CreateDeploymentData(
            branch: 'main',
            trigger: $trigger,
            commit_sha: $commit,
            commit_message: $commit === null ? null : 'feat: pinned commit',
            forceBuild: $forceBuild,
        ),
    );
}

/** What a succeeded deployment leaves behind: it serves the project. */
function reuseMakeLive(Deployment $deployment, RuntimeStatus $runtime = RuntimeStatus::Running): Deployment
{
    $deployment->update(['status' => DeploymentStatus::Succeeded, 'finished_at' => now()]);
    Project::query()->whereKey($deployment->project_id)->update([
        'active_deployment_id' => $deployment->id,
        'runtime_status' => $runtime,
    ]);

    return $deployment->refresh();
}

/** The project's first deployment, made live: it builds and stores a build fingerprint. */
function reuseGoLive(Project $project, User $user, ?string $commit = null): Deployment
{
    return reuseMakeLive(reuseDeploy($project, $user, commit: $commit));
}

function reuseRedeployOverHttp(Project $project, User $user, array $body = []): TestResponse
{
    return test()->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main', ...$body]);
}

/** @return array<string, mixed> */
function reusePayload(Deployment $deployment): array
{
    return AgentCommand::query()->where('deployment_id', $deployment->id)->sole()->payload;
}

function expectReuse(Deployment $deployment, string $sourceId): void
{
    $deployment->refresh();

    expect($deployment->build_mode)->toBe(DeploymentBuildMode::Reused)
        ->and($deployment->image_source_deployment_id)->toBe($sourceId)
        ->and($deployment->build_fallback_reason)->toBeNull()
        ->and($deployment->build_fingerprint)->toHaveLength(64)
        ->and(reusePayload($deployment)['reuse_image_from_deployment_id'] ?? null)->toBe($sourceId);
}

function expectBuild(Deployment $deployment): void
{
    $deployment->refresh();

    expect($deployment->build_mode)->toBe(DeploymentBuildMode::Built)
        ->and($deployment->image_source_deployment_id)->toBeNull()
        ->and(reusePayload($deployment))->not->toHaveKey('reuse_image_from_deployment_id');
}

/** The node claims the deployment's command and reports `$events` (type => metadata). */
function reuseReportEvents(Deployment $deployment, AgentNode $node, array $events): void
{
    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    if ($command->status === AgentCommandStatus::Pending) {
        test()->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    }

    foreach ($events as $type => [$level, $metadata]) {
        test()->withHeaders(databaseNodeHeaders($node))
            ->postJson("/api/agent/v1/commands/{$command->id}/events", [
                'type' => $type,
                'level' => $level,
                'message' => "Agent reported {$type}.",
                'metadata' => $metadata,
                'occurred_at' => now()->toIso8601String(),
            ])
            ->assertOk();
    }
}

// ─── Reuse ───────────────────────────────────────────────────────────────────

test('a manual redeploy with nothing changed at the live commit reuses its image and answers the build block', function (): void {
    $node = reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject(['APP_DEBUG' => 'false']);
    $live = reuseGoLive($project, $team['owner']);

    expect($live->build_mode)->toBe(DeploymentBuildMode::Built)
        ->and($live->agent_node_id)->toBe($node->id)
        ->and($live->commit_sha)->toBe(REUSE_BRANCH_HEAD);

    $response = reuseRedeployOverHttp($project, $team['member'])
        ->assertCreated()
        ->assertJsonPath('data.build', [
            'mode' => 'reused',
            'image_source_deployment_id' => $live->id,
            'image_source_sequence' => $live->sequence,
            'fallback_reason' => null,
        ]);

    $deployment = Deployment::query()->findOrFail($response->json('data.id'));
    expectReuse($deployment, $live->id);

    // Everything else in the payload is a normal deployment's.
    expect(reusePayload($deployment))->toMatchArray([
        'commit_sha' => REUSE_BRANCH_HEAD,
        'builder' => 'auto',
    ])->and(reusePayload($deployment)['environment'])->toHaveKey('APP_DEBUG');
});

test('a password rotation redeploy of the live commit reuses the image', function (): void {
    $node = reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $database = databaseIn($team['workspace'], $node);
    attachDatabase($database, $project);
    $live = reuseGoLive($project, $team['owner'], commit: REUSE_OLDER_COMMIT);

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/rotate-password", ['redeploy_attached' => true])
        ->assertAccepted();

    $command = databaseCommand($database, AgentCommandType::RotateDatabasePassword);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    $deployment = Deployment::query()->where('trigger', DeploymentTrigger::DatabasePasswordRotated)->sole();

    // The live commit, not the branch head, and its image.
    expect($deployment->commit_sha)->toBe(REUSE_OLDER_COMMIT);
    expectReuse($deployment, $live->id);
});

test('attaching or detaching a database and redeploying the same commit reuses the image', function (bool $attachedFirst): void {
    $node = reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $database = databaseIn($team['workspace'], $node);

    if ($attachedFirst) {
        attachDatabase($database, $project);
    }

    $live = reuseGoLive($project, $team['owner']);

    if ($attachedFirst) {
        DatabaseAttachment::query()->where('project_id', $project->id)->delete();
        $project->refresh()->refreshDatabaseFingerprint();
        $project->save();
    } else {
        attachDatabase($database, $project->refresh());
    }

    expect($project->fresh()->requiresRedeploy())->toBeTrue();

    $deployment = Deployment::query()->findOrFail(reuseRedeployOverHttp($project, $team['owner'])->assertCreated()->json('data.id'));

    expectReuse($deployment, $live->id);
    expect(array_key_exists('DATABASE_URL', reusePayload($deployment)['environment']))->toBe(! $attachedFirst);
})->with([
    'after attaching' => [false],
    'after detaching' => [true],
]);

test('changing a runtime variable and redeploying the same commit reuses the image', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject(['APP_DEBUG' => 'false', 'API_URL' => 'https://api.example.test']);
    $live = reuseGoLive($project, $team['owner']);

    EnvironmentVariable::query()->where('project_id', $project->id)->where('key', 'APP_DEBUG')->firstOrFail()->update(['encrypted_value' => 'true']);
    EnvironmentVariable::query()->create(['project_id' => $project->id, 'key' => 'LOG_LEVEL', 'encrypted_value' => 'debug', 'is_secret' => false]);
    EnvironmentVariable::query()->where('project_id', $project->id)->where('key', 'API_URL')->delete();

    $deployment = Deployment::query()->findOrFail(reuseRedeployOverHttp($project, $team['owner'])->assertCreated()->json('data.id'));

    expectReuse($deployment, $live->id);
});

test('a chain of reuses points at the deployment that built the image', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $built = reuseGoLive($project, $team['owner']);

    $second = reuseMakeLive(reuseDeploy($project, $team['owner']));
    expectReuse($second, $built->id);

    $third = reuseDeploy($project, $team['owner']);
    expectReuse($third, $built->id);
});

test('a sleeping or crashed live deployment still has its image, so it is reused', function (RuntimeStatus $runtime): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $live = reuseDeploy($project, $team['owner']);
    reuseMakeLive($live, $runtime);

    expectReuse(reuseDeploy($project, $team['owner']), $live->id);
})->with([
    'sleeping' => [RuntimeStatus::Sleeping],
    'crashed' => [RuntimeStatus::Crashed],
]);

// ─── Build ───────────────────────────────────────────────────────────────────

test('the first deployment of a project builds', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();

    $response = reuseRedeployOverHttp($project, $team['owner'])
        ->assertCreated()
        ->assertJsonPath('data.build', [
            'mode' => 'built',
            'image_source_deployment_id' => null,
            'image_source_sequence' => null,
            'fallback_reason' => null,
        ]);

    $deployment = Deployment::query()->findOrFail($response->json('data.id'));
    expectBuild($deployment);
    expect($deployment->build_fingerprint)->toHaveLength(64);
});

test('a new commit builds', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    reuseGoLive($project, $team['owner'], commit: REUSE_OLDER_COMMIT);

    // The branch head moved on since the live deployment.
    expectBuild(reuseDeploy($project, $team['owner']));
});

test('a changed build setting builds', function (string $attribute, string $value): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    reuseGoLive($project, $team['owner']);

    $project->update([$attribute => $value]);

    expectBuild(reuseDeploy($project, $team['owner']));
})->with([
    'root directory' => ['root_directory', 'apps/web'],
    'build command' => ['build_command', 'npm run build:production'],
    'start command' => ['start_command', 'node server.js'],
]);

test('an empty build setting counts as none, like in the payload', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $live = reuseGoLive($project, $team['owner']);

    $project->update(['build_command' => '']);

    expectReuse(reuseDeploy($project, $team['owner']), $live->id);
});

test('adding, changing, or removing a build-time variable builds', function (string $key, string $change): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject($change === 'added' ? [] : [$key => 'before']);
    reuseGoLive($project, $team['owner']);

    match ($change) {
        'added' => EnvironmentVariable::query()->create(['project_id' => $project->id, 'key' => $key, 'encrypted_value' => 'after', 'is_secret' => false]),
        'changed' => EnvironmentVariable::query()->where('project_id', $project->id)->where('key', $key)->firstOrFail()->update(['encrypted_value' => 'after']),
        'removed' => EnvironmentVariable::query()->where('project_id', $project->id)->where('key', $key)->delete(),
    };

    expectBuild(reuseDeploy($project, $team['owner']));
})->with([
    'VITE_' => ['VITE_API_URL'],
    'NEXT_PUBLIC_' => ['NEXT_PUBLIC_X'],
    'RAILPACK_' => ['RAILPACK_NODE_VERSION'],
    'NODE_ENV' => ['NODE_ENV'],
])->with(['added', 'changed', 'removed']);

test('force_build always builds, and the default does not force', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $live = reuseGoLive($project, $team['owner']);

    $forced = Deployment::query()->findOrFail(
        reuseRedeployOverHttp($project, $team['owner'], ['force_build' => true])
            ->assertCreated()
            ->assertJsonPath('data.build.mode', 'built')
            ->json('data.id'),
    );
    expectBuild($forced);

    $forced->update(['status' => DeploymentStatus::Cancelled]);

    $unforced = Deployment::query()->findOrFail(
        reuseRedeployOverHttp($project, $team['owner'], ['force_build' => false])->assertCreated()->json('data.id'),
    );
    expectReuse($unforced, $live->id);
});

test('a node without the image-reuse capability builds', function (): void {
    reuseNode(array_values(array_diff(REUSE_CAPABILITIES, [AgentNode::IMAGE_REUSE_CAPABILITY])));
    ['team' => $team, 'project' => $project] = reuseProject();
    reuseGoLive($project, $team['owner']);

    expectBuild(reuseDeploy($project, $team['owner']));
});

test('a deployment not placed on the live deployment\'s node builds', function (): void {
    $node = reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $live = reuseGoLive($project, $team['owner']);

    // The node went offline: the deployment waits unassigned.
    $node->update(['last_seen_at' => now()->subHour()]);
    $waiting = reuseDeploy($project, $team['owner']);
    expect($waiting->agent_node_id)->toBeNull();
    expectBuild($waiting);
    $waiting->update(['status' => DeploymentStatus::Cancelled]);

    // A later deployment of the project runs on another node (as after a
    // move), while the live one is still on the first: the scheduler picks
    // the other node, which does not have the live image.
    $node->update(['last_seen_at' => now()]);
    $other = reuseNode(overrides: ['last_seen_at' => now()]);
    Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => $live->sequence + 10,
        'agent_node_id' => $other->id,
    ]);

    $moved = reuseDeploy($project, $team['owner']);
    expect($moved->agent_node_id)->toBe($other->id)
        ->and($project->fresh()->active_deployment_id)->toBe($live->id);
    expectBuild($moved);
});

test('a live deployment from before image reuse has no build fingerprint, so the next one builds once', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $live = reuseGoLive($project, $team['owner']);
    $live->update(['build_fingerprint' => null]);

    $next = reuseMakeLive(reuseDeploy($project, $team['owner']));
    expectBuild($next);

    expectReuse(reuseDeploy($project, $team['owner']), $next->id);
});

// ─── Request ─────────────────────────────────────────────────────────────────

test('force_build must be a boolean', function (mixed $value): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();

    reuseRedeployOverHttp($project, $team['owner'], ['force_build' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('force_build');

    expect(Deployment::query()->count())->toBe(0);
})->with([
    'string' => ['yes'],
    'array' => [[true]],
    'number' => [2],
]);

test('a viewer still cannot deploy, with or without force_build', function (array $body): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();

    reuseRedeployOverHttp($project, $team['viewer'], $body)->assertForbidden();

    expect(Deployment::query()->count())->toBe(0);
})->with([
    'default' => [[]],
    'forced' => [['force_build' => true]],
]);

// ─── Build rate ──────────────────────────────────────────────────────────────

test('a reuse is not checked against the build rate and does not count in its window', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject(profile: ['max_builds_per_hour' => 2]);
    reuseGoLive($project, $team['owner']);

    // Not counted: a forced build still fits after it.
    reuseMakeLive(reuseDeploy($project, $team['owner']));
    $second = reuseMakeLive(reuseDeploy($project, $team['owner'], forceBuild: true));
    expect($second->build_mode)->toBe(DeploymentBuildMode::Built);

    // At the cap, a build is refused with the count of builds only…
    reuseRedeployOverHttp($project, $team['owner'], ['force_build' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUILD_RATE_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 2)
        ->assertJsonPath('current', 2);

    // …and a reuse is still allowed.
    $reused = Deployment::query()->findOrFail(reuseRedeployOverHttp($project, $team['owner'])->assertCreated()->json('data.id'));
    expectReuse($reused, $second->id);
});

// ─── Agent events ────────────────────────────────────────────────────────────

test('deployment.image.reused keeps the plan and the status; runtime.ready still records the image', function (): void {
    $node = reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $live = reuseGoLive($project, $team['owner']);
    $deployment = reuseDeploy($project, $team['owner']);

    reuseReportEvents($deployment, $node, [
        'deployment.image.reused' => ['info', ['image' => 'sakala/project-x:3e91b22a2e56-0198a2f0', 'source_deployment_id' => $live->id, 'builder' => 'railpack']],
    ]);

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->build_mode)->toBe(DeploymentBuildMode::Reused)
        ->and($deployment->events()->where('type', 'deployment.image.reused')->exists())->toBeTrue();

    reuseReportEvents($deployment, $node, [
        'deployment.container.started' => ['info', []],
        'deployment.runtime.ready' => ['info', ['image' => 'sakala/project-x:3e91b22a2e56-0198a2f0', 'builder' => 'railpack']],
    ]);

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Deploying)
        ->and($deployment->image_reference)->toBe('sakala/project-x:3e91b22a2e56-0198a2f0')
        ->and($deployment->build_mode)->toBe(DeploymentBuildMode::Reused);
});

test('deployment.image.reuse_skipped turns a planned reuse into a build with the reason', function (mixed $reason, DeploymentBuildFallbackReason $expected): void {
    $node = reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $live = reuseGoLive($project, $team['owner']);
    $deployment = reuseDeploy($project, $team['owner']);

    reuseReportEvents($deployment, $node, [
        'deployment.image.reuse_skipped' => ['warning', ['source_deployment_id' => $live->id, 'reason' => $reason]],
    ]);

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->build_mode)->toBe(DeploymentBuildMode::Built)
        ->and($deployment->build_fallback_reason)->toBe($expected)
        ->and($deployment->image_source_deployment_id)->toBe($live->id);

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.build', [
            'mode' => 'built',
            'image_source_deployment_id' => $live->id,
            'image_source_sequence' => $live->sequence,
            'fallback_reason' => $expected->value,
        ]);

    // The build that follows moves it on as usual.
    reuseReportEvents($deployment, $node, ['deployment.checkout.started' => ['info', []]]);
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cloning);
})->with([
    'image_missing' => ['image_missing', DeploymentBuildFallbackReason::ImageMissing],
    'image_not_owned' => ['image_not_owned', DeploymentBuildFallbackReason::ImageNotOwned],
    'image_metadata_missing' => ['image_metadata_missing', DeploymentBuildFallbackReason::ImageMetadataMissing],
    'invalid_reference' => ['invalid_reference', DeploymentBuildFallbackReason::InvalidReference],
    'unknown' => ['disk_on_fire', DeploymentBuildFallbackReason::ImageUnavailable],
    'missing' => [null, DeploymentBuildFallbackReason::ImageUnavailable],
]);

test('a fallen-back deployment counts in the build rate window from then on', function (): void {
    $node = reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject(profile: ['max_builds_per_hour' => 2]);
    reuseGoLive($project, $team['owner']);
    $deployment = reuseDeploy($project, $team['owner']);

    reuseReportEvents($deployment, $node, [
        'deployment.image.reuse_skipped' => ['warning', ['source_deployment_id' => null, 'reason' => 'image_missing']],
    ]);
    $deployment->update(['status' => DeploymentStatus::Failed]);

    reuseRedeployOverHttp($project, $team['owner'], ['force_build' => true])
        ->assertUnprocessable()
        ->assertJsonPath('current', 2);
});

test('deployment.image.reuse_skipped on a deployment planned to build changes nothing', function (): void {
    $node = reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $deployment = reuseDeploy($project, $team['owner']);

    reuseReportEvents($deployment, $node, [
        'deployment.image.reuse_skipped' => ['warning', ['source_deployment_id' => null, 'reason' => 'invalid_reference']],
    ]);

    expect($deployment->fresh())
        ->build_mode->toBe(DeploymentBuildMode::Built)
        ->build_fallback_reason->toBeNull();
});

// ─── Resource ────────────────────────────────────────────────────────────────

test('a reused deployment whose source was deleted answers no source', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject();
    $live = reuseGoLive($project, $team['owner']);
    $reused = reuseMakeLive(reuseDeploy($project, $team['owner']));

    // Only purging a project deletes deployments; the foreign key lets go.
    $live->commands()->delete();
    $live->delete();

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$reused->id}")
        ->assertOk()
        ->assertJsonPath('data.build', [
            'mode' => 'reused',
            'image_source_deployment_id' => null,
            'image_source_sequence' => null,
            'fallback_reason' => null,
        ]);

    // Nothing left to point at, so the next one builds.
    expectBuild(reuseDeploy($project, $team['owner']));
});

test('the deployment list answers the build block without a query per row', function (): void {
    reuseNode();
    ['team' => $team, 'project' => $project] = reuseProject(profile: ['max_builds_per_hour' => 50]);
    $built = reuseGoLive($project, $team['owner']);

    $queriesFor = function () use ($team, $project): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($team['owner'], 'web')->getJson("/api/v1/app/projects/{$project->id}/deployments")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    reuseMakeLive(reuseDeploy($project, $team['owner']));
    $two = $queriesFor();

    foreach (range(1, 4) as $i) {
        reuseMakeLive(reuseDeploy($project, $team['owner']));
    }

    expect($queriesFor())->toBe($two);

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments")
        ->assertOk()
        ->assertJsonPath('data.0.build.mode', 'reused')
        ->assertJsonPath('data.0.build.image_source_sequence', $built->sequence)
        ->assertJsonPath('data.5.build.mode', 'built')
        ->assertJsonPath('data.5.build.image_source_sequence', null);
});
