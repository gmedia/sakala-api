<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Actions\Deployment\TransitionDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

/** Commits of the rollback fixtures: an older version and the one after it. */
const ROLLBACK_OLD_COMMIT = '1111111111111111111111111111111111111111';

const ROLLBACK_NEW_COMMIT = '2222222222222222222222222222222222222222';

/** What a docker-driver agent with image rollback advertises. */
const ROLLBACK_CAPABILITIES = [
    'docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', 'release-command',
    AgentCommandType::MANAGED_POSTGRES_CAPABILITY, AgentNode::IMAGE_REUSE_CAPABILITY, AgentNode::IMAGE_ROLLBACK_CAPABILITY,
];

/** One node that runs projects and hosts databases, advertising `$capabilities`. */
function rollbackNode(array $capabilities = ROLLBACK_CAPABILITIES): AgentNode
{
    return databaseNode(overrides: [
        'capabilities' => $capabilities,
        'metadata' => [
            'workloads' => [],
            'database' => ['endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT]],
        ],
    ]);
}

/**
 * A team project on `main`, an inspected plain Node server with the first
 * version's settings and auto-deploy on.
 *
 * @param  array<string, string>  $variables
 * @return array{team: array{workspace: Workspace, owner: User, member: User, viewer: User, outsider: User, admin: User}, project: Project}
 */
function rollbackProject(array $variables = [], array $attributes = [], array $profile = []): array
{
    $team = databaseTeam($profile);
    $project = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create([
        'branch' => 'main',
        ...inspectedProjectAttributes(railpackStackInspection('express')),
        'root_directory' => 'apps/web',
        'build_command' => 'npm run build:v1',
        'start_command' => 'node v1.js',
        'release_command' => 'node migrate-v1.js',
        'configured_port' => 3001,
        'auto_deploy' => true,
        ...$attributes,
    ]);

    foreach ($variables as $key => $value) {
        EnvironmentVariable::query()->create(['project_id' => $project->id, 'key' => $key, 'encrypted_value' => $value, 'is_secret' => true]);
    }

    return ['team' => $team, 'project' => $project];
}

/** A deployment of `$commit` through the real action, as `$user`. */
function rollbackDeploy(Project $project, User $user, string $commit, DeploymentTrigger $trigger = DeploymentTrigger::Manual): Deployment
{
    return app(CreateDeploymentAction::class)->handle(
        project: $project->fresh(),
        user: $user,
        data: new CreateDeploymentData(
            branch: $project->fresh()->branch,
            trigger: $trigger,
            commit_sha: $commit,
            commit_message: "feat: commit {$commit}",
        ),
    );
}

/** The deployment succeeds the way the agent's completion makes it (TransitionDeploymentAction). */
function rollbackSucceed(Deployment $deployment): Deployment
{
    return app(TransitionDeploymentAction::class)->handle($deployment->fresh(), DeploymentStatus::Succeeded);
}

/**
 * Two live versions: the first deployment (old commit, first settings)
 * replaced by a second one (new commit, changed settings and a new variable).
 *
 * @return array{team: array{workspace: Workspace, owner: User, member: User, viewer: User, outsider: User, admin: User}, project: Project, node: AgentNode, old: Deployment, live: Deployment}
 */
function rollbackTwoVersions(array $capabilities = ROLLBACK_CAPABILITIES, array $projectAttributes = [], array $profile = []): array
{
    $node = rollbackNode($capabilities);
    ['team' => $team, 'project' => $project] = rollbackProject(['APP_NAME' => 'one'], $projectAttributes, $profile);

    $old = rollbackSucceed(rollbackDeploy($project, $team['owner'], ROLLBACK_OLD_COMMIT));

    $project->fresh()->update([
        'build_command' => 'npm run build:v2',
        'start_command' => 'node v2.js',
        'release_command' => null,
    ]);
    EnvironmentVariable::query()->create(['project_id' => $project->id, 'key' => 'FEATURE_FLAG', 'encrypted_value' => 'on', 'is_secret' => false]);

    $live = rollbackSucceed(rollbackDeploy($project, $team['owner'], ROLLBACK_NEW_COMMIT));

    return ['team' => $team, 'project' => $project->fresh(), 'node' => $node, 'old' => $old->fresh(), 'live' => $live->fresh()];
}

/** @return array<string, mixed> */
function rollbackPayload(Deployment $deployment): array
{
    return AgentCommand::query()
        ->where('deployment_id', $deployment->id)
        ->where('type', AgentCommandType::DeployProject)
        ->sole()
        ->payload;
}

function rollbackUrl(Project $project, Deployment $target): string
{
    return "/api/v1/app/projects/{$project->id}/deployments/{$target->id}/rollback";
}
