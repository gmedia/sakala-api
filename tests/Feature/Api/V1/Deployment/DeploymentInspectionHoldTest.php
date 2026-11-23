<?php

declare(strict_types=1);

use App\Actions\Project\DeleteProjectAction;
use App\Actions\Project\RequestProjectInspectionAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ProjectInspectionStatus;
use App\Jobs\Deployment\SettleInspectionHoldsJob;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Deployment\DeploymentInspectionHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const HOLD_SHA = 'f00dfeed0123456789abcdef0123456789abcdef';
const HOLD_TOKEN = 'hold-token';

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => HOLD_SHA, 'commit' => ['message' => 'feat: serve with nginx']],
        ], 200),
    ]);
    config(['sakala.deployments.inspection_wait_seconds' => 900]);

    $this->holdNode = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'project-inspection', 'dockerfile-build', 'railpack-info', 'railpack-build'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', HOLD_TOKEN, (string) config('app.key')),
    ]);
});

/** @return array<string, string> */
function holdHeaders(): array
{
    return ['Authorization' => 'Bearer '.HOLD_TOKEN, 'X-Agent-Id' => test()->holdNode->agent_id];
}

function holdProject(array $attributes = []): Project
{
    return Project::factory()->for(User::factory())->create([
        'repository_url' => 'https://github.com/sakala-demo/static-site',
        'branch' => 'main',
        ...$attributes,
    ]);
}

/** Asks for an inspection, as creating a project does; it stays pending. */
function holdInspection(Project $project): AgentCommand
{
    $command = app(RequestProjectInspectionAction::class)->handle($project);
    expect($command)->not->toBeNull()
        ->and($project->fresh()?->inspection_status)->toBe(ProjectInspectionStatus::Pending);

    return $command;
}

function holdDeploy(Project $project, ?string $idempotencyKey = null): Deployment
{
    $id = test()->actingAs($project->user, 'web')
        ->postJson(
            "/api/v1/app/projects/{$project->id}/deployments",
            ['branch' => 'main'],
            $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey],
        )
        ->assertCreated()
        ->assertJsonPath('data.status', 'queued')
        ->json('data.id');

    return Deployment::query()->findOrFail($id);
}

function holdDeployCommand(Deployment $deployment): AgentCommand
{
    return AgentCommand::query()
        ->where('deployment_id', $deployment->id)
        ->where('type', AgentCommandType::DeployProject)
        ->sole();
}

/** @return list<string> The command IDs the node gets when it polls. */
function holdPolledIds(): array
{
    return array_column(test()->withHeaders(holdHeaders())->getJson('/api/agent/v1/commands')->assertOk()->json('data'), 'id');
}

function holdQueueState(Deployment $deployment): ?string
{
    return test()->actingAs($deployment->project->user, 'web')
        ->getJson("/api/v1/app/projects/{$deployment->project_id}/deployments/{$deployment->id}")
        ->assertOk()
        ->json('data.queue_state');
}

/** @param array<string, mixed> $result */
function holdCompleteInspection(AgentCommand $inspection, array $result = []): void
{
    test()->withHeaders(holdHeaders())->postJson("/api/agent/v1/commands/{$inspection->id}/claim", [])->assertOk();
    test()->withHeaders(holdHeaders())
        ->postJson("/api/agent/v1/commands/{$inspection->id}/complete", ['result' => [
            'repository_url' => 'https://github.com/sakala-demo/static-site',
            'commit_sha' => HOLD_SHA,
            'dockerfile_found' => true,
            'env_example_found' => false,
            'compose_found' => false,
            'manifests' => ['Dockerfile'],
            'package_manager' => null,
            'railpack' => [],
            'exposed_ports' => [8080],
            ...$result,
        ]])
        ->assertNoContent();
}

function holdFailInspection(AgentCommand $inspection, string $code): void
{
    test()->withHeaders(holdHeaders())->postJson("/api/agent/v1/commands/{$inspection->id}/claim", [])->assertOk();
    test()->withHeaders(holdHeaders())
        ->postJson("/api/agent/v1/commands/{$inspection->id}/fail", ['error_code' => $code, 'error_message' => 'inspection failed'])
        ->assertNoContent();
}

// ─── Holding ─────────────────────────────────────────────────────────────────

test('a deployment created while the inspection runs is queued, but its command is held from the node', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);

    $deployment = holdDeploy($project);
    $command = holdDeployCommand($deployment);

    expect($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->awaiting_inspection_until?->timestamp)->toBe(now()->addSeconds(900)->timestamp)
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and(holdQueueState($deployment))->toBe('waiting_for_inspection')
        // The node sees the inspection, not the deployment.
        ->and(holdPolledIds())->toBe([$inspection->id]);

    $this->withHeaders(holdHeaders())
        ->postJson("/api/agent/v1/commands/{$command->id}/claim", [])
        ->assertStatus(409)
        ->assertJsonPath('status', 'Pending');

    expect($command->fresh()?->status)->toBe(AgentCommandStatus::Pending);
});

test('the inspection finishing releases the command with the port it found, and nothing else from the click changes', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);
    $deployment = holdDeploy($project);
    $before = holdDeployCommand($deployment)->payload;

    expect($deployment->container_port)->toBe(Project::DEFAULT_CONTAINER_PORT);

    holdCompleteInspection($inspection, ['exposed_ports' => [8080]]);

    $deployment->refresh();
    $command = holdDeployCommand($deployment);

    expect($deployment->container_port)->toBe(8080)
        ->and($deployment->awaiting_inspection_until)->toBeNull()
        ->and($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($command->payload)->toBe([...$before, 'container_port' => 8080])
        ->and(holdQueueState($deployment))->toBe('assigned')
        ->and(holdPolledIds())->toBe([$command->id]);

    $this->withHeaders(holdHeaders())->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
});

test('an inspection without a port releases the command on the default port', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);
    $deployment = holdDeploy($project);

    holdCompleteInspection($inspection, ['dockerfile_found' => false, 'manifests' => ['package.json'], 'exposed_ports' => []]);

    expect($deployment->fresh()?->container_port)->toBe(Project::DEFAULT_CONTAINER_PORT)
        ->and($deployment->fresh()?->awaiting_inspection_until)->toBeNull()
        ->and(holdDeployCommand($deployment)->payload['container_port'])->toBe(Project::DEFAULT_CONTAINER_PORT);
});

test('an inspection that finished before the click holds nothing and the port is already right', function (): void {
    $project = holdProject();
    holdCompleteInspection(holdInspection($project), ['exposed_ports' => [8080]]);

    $deployment = holdDeploy($project);

    expect($deployment->awaiting_inspection_until)->toBeNull()
        ->and($deployment->container_port)->toBe(8080)
        ->and(holdDeployCommand($deployment)->payload['container_port'])->toBe(8080)
        ->and(holdQueueState($deployment))->toBe('assigned');
});

test('a configured port needs no inspection, so nothing is held', function (): void {
    $project = holdProject(['configured_port' => 5000]);
    holdInspection($project);

    $deployment = holdDeploy($project);

    expect($deployment->awaiting_inspection_until)->toBeNull()
        ->and($deployment->container_port)->toBe(5000);
});

test('a project that never asked for an inspection, or could not, deploys at once', function (?ProjectInspectionStatus $status): void {
    $project = holdProject(['inspection_status' => $status]);

    expect(holdDeploy($project)->awaiting_inspection_until)->toBeNull();
})->with([
    'never' => [null],
    'unavailable' => [ProjectInspectionStatus::Unavailable],
    'failed earlier' => [ProjectInspectionStatus::Failed],
]);

test('the same idempotency key while held returns the held deployment', function (): void {
    $project = holdProject();
    holdInspection($project);

    $first = holdDeploy($project, 'click-1');
    $second = holdDeploy($project, 'click-1');

    expect($second->id)->toBe($first->id)
        ->and(Deployment::query()->count())->toBe(1)
        ->and(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->count())->toBe(1);
});

// ─── Failing ─────────────────────────────────────────────────────────────────

test('a failed inspection fails the held deployment with its reason, and the command never runs', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);
    $deployment = holdDeploy($project);

    holdFailInspection($inspection, 'repository_not_found');

    $deployment->refresh();
    $command = holdDeployCommand($deployment);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_code)->toBe('repository_not_found')
        ->and($deployment->failure_stage)->toBe(DeploymentStatus::Queued)
        ->and($deployment->failure_detail)->toBe('Inspeksi proyek gagal: repository_not_found')
        ->and($deployment->awaiting_inspection_until)->toBeNull()
        ->and($command->status)->toBe(AgentCommandStatus::Cancelled)
        ->and($command->error_code)->toBe('repository_not_found')
        ->and(holdPolledIds())->toBe([]);

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.queue_state', null)
        ->assertJsonPath('data.failure.summary', 'Repository tidak ditemukan di GitHub.')
        ->assertJsonPath('data.failure.user_fixable', true);
});

test('an inspection error the classifier does not know fails the deployment as inspection_failed', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);
    $deployment = holdDeploy($project);

    holdFailInspection($inspection, 'inspection_parser_crashed');

    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_code)->toBe(DeploymentInspectionHold::FAILED_CODE)
        ->and($deployment->failure_summary)->toBe('Sakala gagal menganalisis repository sebelum deployment dimulai.')
        ->and($deployment->failure_detail)->toBe('Inspeksi proyek gagal: inspection_parser_crashed');
});

test('an inspection whose node stopped answering fails the deployment once its lease expires', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);
    $deployment = holdDeploy($project);

    $this->withHeaders(holdHeaders())->postJson("/api/agent/v1/commands/{$inspection->id}/claim", [])->assertOk();
    $this->travel(2)->hours();
    $this->artisan('agent:expire-commands')->assertSuccessful();

    expect($deployment->fresh()?->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()?->failure_code)->toBe('command_lease_expired');
});

test('an inspection still pending at the deadline fails the deployment with inspection_timeout; before it, it waits', function (): void {
    $project = holdProject();
    holdInspection($project);
    $deployment = holdDeploy($project);

    $this->travel(899)->seconds();
    $this->artisan('deployments:settle-inspection-holds')
        ->expectsOutput('Released 0, failed 0, still waiting 1 held deployment(s).')
        ->assertSuccessful();
    expect($deployment->fresh()?->status)->toBe(DeploymentStatus::Queued);

    $this->travel(2)->seconds();
    $this->artisan('deployments:settle-inspection-holds')
        ->expectsOutput('Released 0, failed 1, still waiting 0 held deployment(s).')
        ->assertSuccessful();

    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_code)->toBe(DeploymentInspectionHold::TIMEOUT_CODE)
        ->and($deployment->failure_summary)->toBe('Analisis repository belum selesai setelah 15 menit, jadi deployment tidak dimulai.')
        ->and(holdDeployCommand($deployment)->status)->toBe(AgentCommandStatus::Cancelled);
});

// ─── Races and lost jobs ─────────────────────────────────────────────────────

test('the sweep releases a deployment whose settle job was lost', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);
    $deployment = holdDeploy($project);

    Queue::fake();
    holdCompleteInspection($inspection, ['exposed_ports' => [8080]]);
    Queue::assertPushed(SettleInspectionHoldsJob::class, fn (SettleInspectionHoldsJob $job): bool => $job->projectId === $project->id);

    expect($deployment->fresh()?->awaiting_inspection_until)->not->toBeNull();

    $this->artisan('deployments:settle-inspection-holds')
        ->expectsOutput('Released 1, failed 0, still waiting 0 held deployment(s).')
        ->assertSuccessful();

    expect($deployment->fresh()?->container_port)->toBe(8080)
        ->and($deployment->fresh()?->awaiting_inspection_until)->toBeNull();

    // A second run, or the lost job arriving late, changes nothing.
    $updatedAt = $deployment->fresh()?->updated_at;
    (new SettleInspectionHoldsJob($project->id))->handle(app(DeploymentInspectionHold::class));
    expect($deployment->fresh()?->updated_at?->eq($updatedAt))->toBeTrue();
});

test('no settle job is queued for a project with nothing held', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);

    Queue::fake();
    holdCompleteInspection($inspection);

    Queue::assertNotPushed(SettleInspectionHoldsJob::class);
});

test('a port configured while it waits releases it on that port at the next sweep', function (): void {
    $project = holdProject();
    holdInspection($project);
    $deployment = holdDeploy($project);

    $project->update(['configured_port' => 5000]);
    $this->artisan('deployments:settle-inspection-holds')->assertSuccessful();

    expect($deployment->fresh()?->container_port)->toBe(5000)
        ->and(holdDeployCommand($deployment)->payload['container_port'])->toBe(5000);
});

test('a superseded inspection does not release; the newer one does', function (): void {
    $project = holdProject();
    $old = holdInspection($project);
    $deployment = holdDeploy($project);

    // Another root directory asks again for the same commit.
    $project->update(['root_directory' => 'apps/web']);
    $this->travel(1)->seconds();
    $new = holdInspection($project->fresh());

    holdCompleteInspection($old, ['exposed_ports' => [9000]]);
    expect($deployment->fresh()?->awaiting_inspection_until)->not->toBeNull();

    holdCompleteInspection($new, ['exposed_ports' => [8080]]);
    expect($deployment->fresh()?->awaiting_inspection_until)->toBeNull()
        ->and($deployment->fresh()?->container_port)->toBe(8080);
});

test('cancelling a held deployment closes it at once, and settling later leaves it cancelled', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);
    $deployment = holdDeploy($project);

    $this->actingAs($project->user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/cancel")
        ->assertSuccessful();

    holdCompleteInspection($inspection);

    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->awaiting_inspection_until)->toBeNull()
        ->and(holdDeployCommand($deployment)->status)->toBe(AgentCommandStatus::Cancelled)
        ->and(holdPolledIds())->toBe([]);
});

test('deleting the project while it waits cancels the deployment, and the inspection outcome changes nothing', function (): void {
    $project = holdProject();
    $inspection = holdInspection($project);
    $deployment = holdDeploy($project);

    app(DeleteProjectAction::class)->handle($project, $project->user);

    expect($deployment->fresh()?->status)->toBe(DeploymentStatus::Cancelled);

    $this->artisan('deployments:settle-inspection-holds')->assertSuccessful();

    $deployment->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->awaiting_inspection_until)->toBeNull()
        ->and($inspection->fresh()?->status)->toBe(AgentCommandStatus::Cancelled)
        ->and(holdPolledIds())->toBe([]);
});

// ─── With deploy_when_ready ──────────────────────────────────────────────────

test('a database that is ready before the inspection: the deployment it starts waits for the inspection too', function (): void {
    $databaseNode = databaseNode(overrides: [
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', AgentCommandType::MANAGED_POSTGRES_CAPABILITY],
        'metadata' => [
            'runtime' => ['region' => null],
            'workloads' => [],
            'database' => ['endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT], 'available' => true],
        ],
    ]);
    $team = databaseTeam();
    $project = Project::factory()->inWorkspace($team['workspace'])->create([
        'repository_url' => 'https://github.com/sakala-demo/static-site',
        'branch' => 'main',
    ]);
    $inspection = holdInspection($project);

    $databaseId = $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases", ['project_id' => $project->id, 'deploy_when_ready' => true])
        ->assertCreated()
        ->json('data.id');

    $create = AgentCommand::query()->where('type', AgentCommandType::CreateDatabase)->sole();
    $create->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(databaseNodeHeaders($databaseNode))
        ->postJson("/api/agent/v1/commands/{$create->id}/complete", ['result' => null])
        ->assertNoContent();

    $deployment = Deployment::query()->sole();

    expect($deployment->trigger)->toBe(DeploymentTrigger::DatabaseReady)
        ->and($deployment->awaiting_inspection_until)->not->toBeNull()
        ->and(holdDeployCommand($deployment)->payload['environment'])->toHaveKey('DATABASE_URL');

    holdCompleteInspection($inspection, ['exposed_ports' => [8080]]);

    $deployment->refresh();

    expect($databaseId)->not->toBeNull()
        ->and($deployment->awaiting_inspection_until)->toBeNull()
        ->and($deployment->container_port)->toBe(8080)
        ->and(holdDeployCommand($deployment)->payload['container_port'])->toBe(8080)
        ->and(Deployment::query()->count())->toBe(1);
});
