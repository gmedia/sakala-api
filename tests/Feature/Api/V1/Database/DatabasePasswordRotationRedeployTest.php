<?php

declare(strict_types=1);

use App\Actions\Attachment\RedeployAfterCredentialRotationAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use App\Services\Database\DatabaseCommandService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const ROTATION_LIVE_COMMIT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

const ROTATION_BRANCH_HEAD = '9c1f0e2d3b4a5968778695a4b3c2d1e0f9a8b7c6';

/** GitHub answers ROTATION_BRANCH_HEAD; only reached when a redeploy has to ask for the branch head. */
function fakeRotationBranchHead(): void
{
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => ROTATION_BRANCH_HEAD, 'commit' => ['message' => 'feat: newer commit']],
        ], 200),
    ]);
}

/** One node that runs projects and hosts the databases, as on a single-node install. */
function rotationNode(): AgentNode
{
    return databaseNode(overrides: [
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', AgentCommandType::MANAGED_POSTGRES_CAPABILITY],
        'metadata' => [
            'workloads' => [],
            'database' => ['endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT]],
        ],
    ]);
}

/** A project of the workspace serving a succeeded deployment of ROTATION_LIVE_COMMIT on `main`. */
function rotationServedProject(Workspace $workspace, AgentNode $node, array $attributes = []): Project
{
    $project = Project::factory()->inWorkspace($workspace)->create([
        'branch' => 'main',
        'runtime_status' => RuntimeStatus::Running,
        ...$attributes,
    ]);
    $deployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'branch' => 'main',
        'commit_sha' => ROTATION_LIVE_COMMIT,
        'commit_message' => 'feat: live version',
        'agent_node_id' => $node->id,
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);

    return $project->refresh();
}

function rotateDatabase(ManagedDatabase $database, array $body = []): TestResponse
{
    return test()->postJson("/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/rotate-password", $body);
}

/** The node reports the newest rotation of the database as done. */
function completeRotation(ManagedDatabase $database, AgentNode $node): AgentCommand
{
    $command = databaseCommand($database, AgentCommandType::RotateDatabasePassword);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    test()->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    return $command->refresh();
}

function rotationDeployments(): Collection
{
    return Deployment::query()->where('trigger', DeploymentTrigger::DatabasePasswordRotated)->get();
}

function showDatabase(ManagedDatabase $database): TestResponse
{
    return test()->getJson("/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}");
}

test('a completed rotation with redeploy_attached redeploys the live commit of every attached project with a live deployment', function (): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $served = rotationServedProject($team['workspace'], $node, ['name' => 'A served']);
    $neverDeployed = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'B never deployed', 'branch' => 'main']);
    attachDatabase($database, $served);
    attachDatabase($database, $neverDeployed);

    $this->actingAs($team['member'], 'web');

    rotateDatabase($database, ['redeploy_attached' => true])
        ->assertAccepted()
        ->assertJsonPath('data.password_rotation.status', 'pending')
        ->assertJsonPath('data.password_rotation.completed_at', null)
        ->assertJsonPath('data.password_rotation.redeploy_attached', true)
        ->assertJsonPath('data.password_rotation.redeploys', null);

    // Nothing is redeployed before the node reports.
    expect(rotationDeployments())->toBeEmpty();

    $command = completeRotation($database, $node);
    $newPassword = (string) $database->fresh()->password;

    $deployment = rotationDeployments()->sole();
    $deployCommand = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->project_id)->toBe($served->id)
        ->and($deployment->requested_by)->toBe($team['member']->id)
        ->and($deployment->commit_sha)->toBe(ROTATION_LIVE_COMMIT)
        ->and($deployment->commit_message)->toBe('feat: live version')
        ->and($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and(Crypt::decryptString($deployCommand->payload['environment']['DB_PASSWORD']))->toBe($newPassword)
        ->and($command->payload)->not->toHaveKey('redeploy_attached');

    showDatabase($database)
        ->assertOk()
        ->assertJsonPath('data.password_rotation.status', 'completed')
        ->assertJsonPath('data.password_rotation.requested_at', $command->created_at->toAtomString())
        ->assertJsonPath('data.password_rotation.completed_at', $command->completed_at->toAtomString())
        ->assertJsonPath('data.password_rotation.failed_at', null)
        ->assertJsonPath('data.password_rotation.redeploys', [
            ['project_id' => $served->id, 'deployment_id' => $deployment->id, 'skipped_reason' => null],
            ['project_id' => $neverDeployed->id, 'deployment_id' => null, 'skipped_reason' => 'no_active_deployment'],
        ]);

    expect(AuditEvent::query()->where('action', 'database.password_rotation_redeploys_queued')->sole())
        ->actor_id->toBe((string) $team['member']->id)
        ->metadata->toMatchArray(['command_id' => $command->id]);

    // Running again changes nothing.
    app(RedeployAfterCredentialRotationAction::class)->handle($command->id);

    expect(rotationDeployments())->toHaveCount(1)
        ->and(AuditEvent::query()->where('action', 'database.password_rotation_redeploys_queued')->count())->toBe(1);
});

test('without redeploy_attached a completed rotation redeploys nothing', function (array $body): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = rotationServedProject($team['workspace'], $node);
    attachDatabase($database, $project);
    $project->activeDeployment->update(['config_fingerprint' => $project->fresh()->deploymentConfigFingerprint()]);

    $this->actingAs($team['owner'], 'web');
    rotateDatabase($database, $body)->assertAccepted()->assertJsonPath('data.password_rotation.redeploy_attached', false);
    completeRotation($database, $node);

    expect(rotationDeployments())->toBeEmpty()
        ->and(Deployment::query()->count())->toBe(1)
        ->and($project->fresh()->requiresRedeploy())->toBeTrue();

    showDatabase($database)
        ->assertJsonPath('data.password_rotation.status', 'completed')
        ->assertJsonPath('data.password_rotation.redeploys', null);
})->with([
    'omitted' => [[]],
    'false' => [['redeploy_attached' => false]],
]);

test('a failed rotation keeps the old password, shows failed, and redeploys nothing', function (): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $old = (string) $database->password;
    attachDatabase($database, rotationServedProject($team['workspace'], $node));

    $this->actingAs($team['owner'], 'web');
    rotateDatabase($database, ['redeploy_attached' => true])->assertAccepted();

    $command = databaseCommand($database, AgentCommandType::RotateDatabasePassword);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'database_admin_unavailable', 'error_message' => 'instance down'])
        ->assertNoContent();

    expect($database->fresh()->password)->toBe($old)
        ->and(rotationDeployments())->toBeEmpty();

    showDatabase($database)
        ->assertJsonPath('data.password_rotation.status', 'failed')
        ->assertJsonPath('data.password_rotation.completed_at', null)
        ->assertJsonPath('data.password_rotation.failed_at', $command->fresh()->failed_at->toAtomString())
        ->assertJsonPath('data.password_rotation.redeploys', null)
        ->assertJsonPath('data.last_failure.code', 'database_admin_unavailable');
});

test('a project that cannot be redeployed is skipped with its reason and the rotation still completes', function (): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    // A deployment queued before the rotation fills the one-per-project slot.
    $busy = rotationServedProject($team['workspace'], $node, ['name' => 'A busy']);
    Deployment::factory()->for($busy)->create(['status' => DeploymentStatus::Queued, 'sequence' => 2]);
    $suspended = rotationServedProject($team['workspace'], $node, ['name' => 'B suspended', 'status' => ProjectStatus::Suspended]);
    $healthy = rotationServedProject($team['workspace'], $node, ['name' => 'C healthy']);

    foreach ([$busy, $suspended, $healthy] as $project) {
        attachDatabase($database, $project);
    }

    $this->actingAs($team['owner'], 'web');
    rotateDatabase($database, ['redeploy_attached' => true])->assertAccepted();
    $command = completeRotation($database, $node);

    $deployment = rotationDeployments()->sole();

    expect($deployment->project_id)->toBe($healthy->id)
        ->and($command->status)->toBe(AgentCommandStatus::Succeeded)
        ->and($database->fresh()->password)->toBe(app(DatabaseCommandService::class)->passwordOf($command));

    showDatabase($database)
        ->assertJsonPath('data.password_rotation.status', 'completed')
        ->assertJsonPath('data.password_rotation.redeploys', [
            ['project_id' => $busy->id, 'deployment_id' => null, 'skipped_reason' => 'active_deployment_limit'],
            ['project_id' => $suspended->id, 'deployment_id' => null, 'skipped_reason' => 'project_suspended'],
            ['project_id' => $healthy->id, 'deployment_id' => $deployment->id, 'skipped_reason' => null],
        ]);
});

test('a requester who left the workspace is not deployed as', function (): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = rotationServedProject($team['workspace'], $node);
    attachDatabase($database, $project);

    $this->actingAs($team['member'], 'web');
    rotateDatabase($database, ['redeploy_attached' => true])->assertAccepted();

    WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->where('user_id', $team['member']->id)->delete();

    completeRotation($database, $node);

    expect(rotationDeployments())->toBeEmpty();

    $this->actingAs($team['owner'], 'web');
    showDatabase($database)->assertJsonPath('data.password_rotation.redeploys.0.skipped_reason', 'requester_cannot_deploy');
});

test('an unexpected error on one project is reported and does not stop the others', function (): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    // On another branch since its live deployment: the redeploy asks GitHub for the branch head, which fails.
    $moved = rotationServedProject($team['workspace'], $node, ['name' => 'A moved']);
    $moved->update(['branch' => 'develop']);
    $healthy = rotationServedProject($team['workspace'], $node, ['name' => 'B healthy']);
    attachDatabase($database, $moved);
    attachDatabase($database, $healthy);

    Http::fake(['api.github.com/*' => Http::response(['message' => 'Server Error'], 500)]);

    $this->actingAs($team['owner'], 'web');
    rotateDatabase($database, ['redeploy_attached' => true])->assertAccepted();
    completeRotation($database, $node);

    showDatabase($database)
        ->assertJsonPath('data.password_rotation.status', 'completed')
        ->assertJsonPath('data.password_rotation.redeploys.0.skipped_reason', 'failed')
        ->assertJsonPath('data.password_rotation.redeploys.1.deployment_id', rotationDeployments()->sole()->id);
});

test('a redeploy after moving branches deploys the branch head', function (): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = rotationServedProject($team['workspace'], $node);
    $project->update(['branch' => 'develop']);
    attachDatabase($database, $project);
    fakeRotationBranchHead();

    $this->actingAs($team['owner'], 'web');
    rotateDatabase($database, ['redeploy_attached' => true])->assertAccepted();
    completeRotation($database, $node);

    expect(rotationDeployments()->sole())
        ->branch->toBe('develop')
        ->commit_sha->toBe(ROTATION_BRANCH_HEAD);
});

test('redeploy_attached must be a boolean, and viewers still cannot rotate', function (): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web');
    rotateDatabase($database, ['redeploy_attached' => 'soon'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('redeploy_attached');

    $this->actingAs($team['viewer'], 'web');
    rotateDatabase($database, ['redeploy_attached' => true])->assertForbidden();

    expect(AgentCommand::query()->where('type', AgentCommandType::RotateDatabasePassword)->count())->toBe(0);
});

test('the rotation request and its control-plane context never reach the node', function (): void {
    $node = rotationNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web');
    rotateDatabase($database, ['redeploy_attached' => true])->assertAccepted();

    $command = databaseCommand($database, AgentCommandType::RotateDatabasePassword);

    expect(array_keys($command->payload))->toBe(['role', AgentCommandPayloadMaterializer::PASSWORD_KEY])
        ->and($command->request_context)->toMatchArray(['actor_id' => (string) $team['owner']->id, 'redeploy_attached' => true])
        ->and(AuditEvent::query()->where('action', 'database.password_rotation_requested')->sole()->metadata)
        ->toMatchArray(['command_id' => $command->id, 'redeploy_attached' => true]);

    $response = $this->withHeaders(databaseNodeHeaders($node))->getJson('/api/agent/v1/commands')->assertOk();

    expect(json_encode($response->json()))->not->toContain('redeploy_attached')->not->toContain('request_context');
});

test('a database whose password was never rotated has no password_rotation', function (): void {
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], rotationNode());

    $this->actingAs($team['viewer'], 'web');
    showDatabase($database)->assertOk()->assertJsonPath('data.password_rotation', null);
});
