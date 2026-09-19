<?php

declare(strict_types=1);

use App\Actions\Agent\FailAgentCommandAction;
use App\Actions\Project\RequestProjectInspectionAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\ProjectInspectionStatus;
use App\Enums\ProjectStatus;
use App\Models\AgentCommand;
use App\Models\AgentCommandReport;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const INSPECT_SHA = '0123456789abcdef0123456789abcdef01234567';

function fakeBranchHead(int $status = 200): void
{
    Http::fake([
        'api.github.com/repos/*/commits*' => $status === 200
            ? Http::response([['sha' => INSPECT_SHA, 'commit' => ['message' => 'feat: initial']]], 200)
            : Http::response(['message' => 'error'], $status),
    ]);
}

function inspectorNode(string $token = 'inspect-token', array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'project-inspection', 'dockerfile-build', 'railpack-info', 'railpack-build'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ...$overrides,
    ]);
}

function inspectorHeaders(AgentNode $node, string $token = 'inspect-token'): array
{
    return ['Authorization' => 'Bearer '.$token, 'X-Agent-Id' => $node->agent_id];
}

/** @return array{project: Project, command: AgentCommand} */
function createProjectWithPreview(User $user): array
{
    $response = test()->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'Preview App',
            'repository_url' => 'https://github.com/sakala-demo/preview-app',
            'branch' => 'main',
        ])
        ->assertCreated()
        ->assertJsonPath('data.preview_status', 'pending')
        ->assertJsonPath('data.inspection', null);

    $project = Project::query()->findOrFail($response->json('data.id'));
    $command = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::InspectProject)->sole();

    return compact('project', 'command');
}

function inspectionResult(): array
{
    return [
        'repository_url' => 'https://github.com/sakala-demo/preview-app',
        'commit_sha' => INSPECT_SHA,
        'dockerfile_found' => false,
        'env_example_found' => true,
        'compose_found' => false,
        'manifests' => ['package.json', 'package-lock.json'],
        'package_manager' => 'npm',
        'railpack' => ['providers' => ['node'], 'internal' => ['plan' => 'raw']],
    ];
}

// ─── Creation ────────────────────────────────────────────────────────────────

test('creating a project requests an inspection pinned to an eligible node', function (): void {
    fakeBranchHead();
    $node = inspectorNode();
    ['project' => $project, 'command' => $command] = createProjectWithPreview(User::factory()->create());

    expect($command->agent_node_id)->toBe($node->id)
        ->and($command->deployment_id)->toBeNull()
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($command->expires_at)->toBeNull()
        ->and($command->idempotency_key)->toBe("inspect:{$project->id}:".INSPECT_SHA)
        ->and($command->payload)->toBe([
            'repository_url' => 'https://github.com/sakala-demo/preview-app',
            'commit_sha' => INSPECT_SHA,
            'repository_access' => 'public',
        ])
        ->and($project->inspection_status)->toBe(ProjectInspectionStatus::Pending);

    // The wire shape matches the v0.1.0 inspect-project fixture (minus identifiers).
    $fixture = agentCommandFixture('inspect-project');
    $item = $this->withHeaders(inspectorHeaders($node))->getJson('/api/agent/v1/commands')->assertOk()->json('data.0');
    expect(array_keys($item))->toBe(array_keys($fixture))
        ->and($item['type'])->toBe('InspectProject')
        ->and($item['deployment_id'])->toBeNull()
        ->and(array_keys($item['payload']))->toBe(['repository_url', 'commit_sha', 'repository_access']);
});

test('without an eligible node the inspection waits unassigned but the project is still created', function (): void {
    fakeBranchHead();
    inspectorNode(overrides: ['capabilities' => ['docker-runtime']]);
    ['project' => $project, 'command' => $command] = createProjectWithPreview(User::factory()->create());

    expect($command->agent_node_id)->toBeNull()
        ->and($project->inspection_status)->toBe(ProjectInspectionStatus::Pending);

    $node = inspectorNode('late-token');
    $this->artisan('agent:assign-commands')->expectsOutputToContain('Assigned 1 agent command(s).');
    expect($command->fresh()->agent_node_id)->toBe($node->id);
});

test('an unresolvable branch head never blocks creation and is reported as unavailable', function (int $status, string $code): void {
    fakeBranchHead($status);
    inspectorNode();

    $response = $this->actingAs(User::factory()->create(), 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'No Preview',
            'repository_url' => 'https://github.com/sakala-demo/no-preview',
            'branch' => 'missing',
        ])
        ->assertCreated()
        ->assertJsonPath('data.preview_status', 'unavailable')
        ->assertJsonPath('data.inspection_error_code', $code);

    expect(AgentCommand::query()->where('project_id', $response->json('data.id'))->exists())->toBeFalse();
})->with([
    'branch missing' => [404, 'branch_not_found'],
    'github down' => [503, 'github_unavailable'],
]);

test('requesting an inspection for the same commit again reuses the command', function (): void {
    fakeBranchHead();
    inspectorNode();
    ['project' => $project, 'command' => $command] = createProjectWithPreview(User::factory()->create());

    $again = app(RequestProjectInspectionAction::class)->handle($project->fresh());

    expect($again?->id)->toBe($command->id)
        ->and(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(1);
});

// ─── Agent flow ──────────────────────────────────────────────────────────────

test('the agent inspects the project and the console sees the stable preview fields', function (): void {
    fakeBranchHead();
    $node = inspectorNode();
    $user = User::factory()->create();
    ['project' => $project, 'command' => $command] = createProjectWithPreview($user);
    $headers = inspectorHeaders($node);

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", [
        'type' => 'project.inspection.started', 'level' => 'info', 'message' => 'Inspecting repository.',
        'metadata' => [], 'occurred_at' => now()->toIso8601String(),
    ])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/logs", [
        'stream' => 'stdout', 'message' => '[railpack] detected node', 'recorded_at' => now()->toIso8601String(),
    ])->assertOk();

    expect(AgentCommandReport::query()->where('agent_command_id', $command->id)->count())->toBe(2)
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Running);

    $this->withHeaders($headers)
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => inspectionResult()])
        ->assertNoContent();

    $project->refresh();
    expect($project->inspection_status)->toBe(ProjectInspectionStatus::Succeeded)
        ->and($project->inspected_at)->not->toBeNull()
        ->and($project->inspection['railpack'])->toBe(['providers' => ['node'], 'internal' => ['plan' => 'raw']])
        ->and(AuditEvent::query()->where('action', 'project.inspection_completed')->where('subject_id', $project->id)->exists())->toBeTrue();

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.preview_status', 'succeeded')
        ->assertJsonPath('data.inspection.package_manager', 'npm')
        ->assertJsonPath('data.inspection.dockerfile_found', false)
        ->assertJsonPath('data.inspection.env_example_found', true)
        ->assertJsonPath('data.inspection.manifests', ['package.json', 'package-lock.json'])
        ->assertJsonPath('data.inspection.commit_sha', INSPECT_SHA)
        ->assertJsonMissingPath('data.inspection.railpack');
});

test('a stale inspection never overwrites a newer one', function (): void {
    // First call resolves the original head, the second a newer commit.
    Http::fakeSequence('api.github.com/repos/*/commits*')
        ->push([['sha' => INSPECT_SHA, 'commit' => ['message' => 'feat: initial']]])
        ->push([['sha' => str_repeat('b', 40), 'commit' => ['message' => 'newer']]]);
    $node = inspectorNode();
    ['project' => $project, 'command' => $old] = createProjectWithPreview(User::factory()->create());
    $this->travel(1)->minute();

    $new = app(RequestProjectInspectionAction::class)->handle($project->fresh());
    expect($new)->not->toBeNull()->and($new?->id)->not->toBe($old->id);

    $headers = inspectorHeaders($node);
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$old->id}/claim", [])->assertOk();
    $this->withHeaders($headers)
        ->postJson("/api/agent/v1/commands/{$old->id}/complete", ['result' => inspectionResult()])
        ->assertNoContent();

    expect($project->fresh()->inspection_status)->toBe(ProjectInspectionStatus::Pending)
        ->and($project->fresh()->inspection)->toBeNull();

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$new->id}/claim", [])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$new->id}/fail", [
        'error_code' => 'runtime_repository_failed', 'error_message' => 'clone failed',
    ])->assertNoContent();

    expect($project->fresh()->inspection_status)->toBe(ProjectInspectionStatus::Failed)
        ->and($project->fresh()->inspection_error_code)->toBe('runtime_repository_failed');

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.preview_status', 'failed')
        ->assertJsonPath('data.inspection_error_code', 'runtime_repository_failed')
        ->assertJsonPath('data.inspection', null);
});

test('a noop runtime completes the inspection with a null result without a preview', function (): void {
    fakeBranchHead();
    $node = inspectorNode();
    ['project' => $project, 'command' => $command] = createProjectWithPreview(User::factory()->create());
    $headers = inspectorHeaders($node);

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])->assertNoContent();

    expect($project->fresh()->inspection_status)->toBe(ProjectInspectionStatus::Succeeded)
        ->and($project->fresh()->inspection['manifests'])->toBe([]);
});

test('inspections are withheld while the project is suspended', function (): void {
    fakeBranchHead();
    $node = inspectorNode();
    ['project' => $project, 'command' => $command] = createProjectWithPreview(User::factory()->create());
    $project->update(['status' => ProjectStatus::Suspended]);

    $this->withHeaders(inspectorHeaders($node))->getJson('/api/agent/v1/commands')->assertOk()->assertExactJson(['data' => []]);
    $this->withHeaders(inspectorHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertStatus(409);
});

// ─── Concurrency ─────────────────────────────────────────────────────────────

test('a late inspection failure cannot overwrite a newer request that holds the project lock', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Requires PostgreSQL row-level locking; ignore SQLite which does not support FOR UPDATE.',
        );
    }

    fakeBranchHead();
    $node = inspectorNode();
    ['project' => $project, 'command' => $old] = createProjectWithPreview(User::factory()->create());

    $secondary = 'pgsql_secondary';

    config([
        "database.connections.{$secondary}" => config(
            'database.connections.'.DB::getDefaultConnection(),
        ),
    ]);

    DB::purge($secondary);

    $defaultConnection = DB::getDefaultConnection();

    DB::beginTransaction();

    try {
        /*
         * Transaction A is a newer inspection request: it holds the project
         * row lock (as RequestProjectInspectionAction does), creates the
         * newer command, and marks the preview pending.
         */
        $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        $newer = AgentCommand::factory()->create([
            'type' => AgentCommandType::InspectProject,
            'status' => AgentCommandStatus::Pending,
            'project_id' => $locked->id,
            'agent_node_id' => $node->id,
            'created_at' => $old->created_at->addSecond(),
            'payload' => [],
        ]);
        $locked->update(['inspection_status' => ProjectInspectionStatus::Pending, 'inspection_error_code' => null]);

        /*
         * Session B is the old command failing. It must wait on the project
         * row before it may even look for a newer command.
         */
        $secondaryConnection = DB::connection($secondary);
        $secondaryConnection->statement('SET lock_timeout = 250');

        $failException = null;

        try {
            DB::setDefaultConnection($secondary);

            $old->update(['status' => AgentCommandStatus::Claimed]);
            app(FailAgentCommandAction::class)->handle(
                agent: $node,
                commandId: $old->id,
                errorCode: 'runtime_repository_failed',
                errorMessage: 'clone failed',
            );
        } catch (QueryException $e) {
            // SQLSTATE 55P03 = lock_not_available: blocked by the newer request.
            expect($e->getCode())->toBe('55P03');

            $failException = $e;
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }

        expect($failException)->toBeInstanceOf(QueryException::class);

        DB::commit();
    } catch (Throwable $e) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        throw $e;
    } finally {
        DB::purge($secondary);
    }

    // With the newer request committed, the old failure is recognised as
    // superseded and leaves the preview pending for the newer command.
    $old->update(['status' => AgentCommandStatus::Claimed]);
    app(FailAgentCommandAction::class)->handle(
        agent: $node,
        commandId: $old->id,
        errorCode: 'runtime_repository_failed',
        errorMessage: 'clone failed',
    );

    expect($project->fresh()->inspection_status)->toBe(ProjectInspectionStatus::Pending)
        ->and($project->fresh()->inspection_error_code)->toBeNull()
        ->and($newer->fresh()->status)->toBe(AgentCommandStatus::Pending);
});
