<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentFailureCategory;
use App\Enums\DeploymentStatus;
use App\Enums\FinalizationDeferredReason;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '0123456789abcdef0123456789abcdef01234567', 'commit' => ['message' => 'feat: first']],
        ], 200),
    ]);
});

function flowNode(string $token, array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ...$overrides,
    ]);
}

function flowHeaders(AgentNode $agent, string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $agent->agent_id,
    ];
}

/**
 * Create a project with a secret and deploy it through the console API.
 *
 * @return array{project: Project, deployment: Deployment, command: AgentCommand}
 */
function deployThroughConsole(?Project $project = null): array
{
    $project ??= Project::factory()->create([
        'user_id' => User::factory()->create()->id,
        'branch' => 'main',
        'status' => ProjectStatus::Active,
    ]);
    $user = $project->user;
    EnvironmentVariable::query()->create([
        'project_id' => $project->id,
        'key' => 'DATABASE_URL',
        'encrypted_value' => 'postgres://user:s3cr3t-password@db/app',
        'is_secret' => true,
    ]);

    $response = test()->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    $deployment = Deployment::query()->findOrFail($response->json('data.id'));
    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    return compact('project', 'deployment', 'command');
}

function phaseEvent(string $type, array $metadata = []): array
{
    return [
        'type' => $type,
        'level' => 'info',
        'message' => "Agent reported {$type}.",
        'metadata' => $metadata,
        'occurred_at' => now()->toIso8601String(),
    ];
}

// ─── Secret materialisation ──────────────────────────────────────────────────

test('only the pinned node receives the environment in plaintext while the database keeps ciphertext', function (): void {
    $node = flowNode('pinned-token');
    $other = flowNode('other-token');
    ['command' => $command] = deployThroughConsole();

    expect($command->agent_node_id)->toBe($node->id);

    $rawPayload = (string) DB::table('agent_commands')->where('id', $command->id)->value('payload');
    expect($rawPayload)->not->toContain('s3cr3t-password')
        ->and($rawPayload)->toContain('"repository_access":"public"');

    $poll = $this->withHeaders(flowHeaders($node, 'pinned-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $payload = $poll->json('data.0.payload');
    expect($payload['environment'])->toBe(['DATABASE_URL' => 'postgres://user:s3cr3t-password@db/app'])
        ->and($payload['repository_access'])->toBe('public')
        ->and($payload)->toHaveKeys(['repository_url', 'commit_sha', 'domain', 'container_port', 'builder', 'resources', 'timeouts', 'log_bounds']);

    $this->withHeaders(flowHeaders($other, 'other-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertExactJson(['data' => []]);

    $conflict = $this->withHeaders(flowHeaders($other, 'other-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim", [])
        ->assertStatus(409);

    expect($conflict->getContent())->not->toContain('s3cr3t-password')
        ->and($conflict->getContent())->not->toContain('environment');

    $claim = $this->withHeaders(flowHeaders($node, 'pinned-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim", [])
        ->assertOk();

    expect($claim->json('data.payload.environment.DATABASE_URL'))->toBe('postgres://user:s3cr3t-password@db/app');
});

test('a deploy command with no environment variables still sends an environment object', function (): void {
    $node = flowNode('empty-env-token');
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'branch' => 'main']);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    $response = $this->withHeaders(flowHeaders($node, 'empty-env-token'))
        ->getJson('/api/agent/v1/commands')
        ->assertOk();

    expect($response->getContent())->toContain('"environment":{}');
});

// ─── Event-driven lifecycle ──────────────────────────────────────────────────

test('agent phase events move the deployment forward and capture the built image', function (): void {
    $node = flowNode('phase-token');
    ['project' => $project, 'deployment' => $deployment, 'command' => $command] = deployThroughConsole();
    $headers = flowHeaders($node, 'phase-token');

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", phaseEvent('command.claimed'))->assertOk();
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Queued)
        ->and($command->fresh()->status)->toBe(AgentCommandStatus::Running);

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", phaseEvent('deployment.checkout.started'))->assertOk();
    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Cloning)
        ->and($deployment->started_at)->not->toBeNull()
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Deploying);
    $startedAt = $deployment->started_at;

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", phaseEvent('deployment.build.started'))->assertOk();
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Building);

    // Retried or out-of-order events never move the deployment backwards.
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", phaseEvent('deployment.checkout.started'))->assertOk();
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Building);

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", phaseEvent('deployment.container.started'))->assertOk();
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Deploying);

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", phaseEvent('deployment.runtime.ready', [
        'builder' => 'dockerfile',
        'container' => 'sakala-student-demo',
        'domain' => 'student-demo.run.sakala.localhost',
        'image' => 'sakala/student-demo:0123456',
        'resources' => ['memory_mb' => 256, 'cpu_millis' => 500, 'pids_limit' => 128],
    ]))->assertOk();

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Routing)
        ->and($deployment->image_reference)->toBe('sakala/student-demo:0123456')
        ->and($deployment->started_at?->equalTo($startedAt))->toBeTrue()
        ->and($deployment->finished_at)->toBeNull();

    // The agent's own timeline is the timeline: no synthetic phase rows.
    expect($deployment->events()->where('type', 'deployment.cloning')->exists())->toBeFalse()
        ->and($deployment->events()->where('type', 'deployment.checkout.started')->count())->toBe(2);
});

test('completion is authoritative and stores the applied resources', function (): void {
    $node = flowNode('complete-token');
    ['project' => $project, 'deployment' => $deployment, 'command' => $command] = deployThroughConsole();
    $headers = flowHeaders($node, 'complete-token');

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", phaseEvent('deployment.build.started'))->assertOk();

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/complete", [
        'result' => [
            'requested_resources' => ['memory_mb' => null, 'cpu_millis' => null, 'pids_limit' => null],
            'applied_resources' => ['memory_mb' => 256, 'cpu_millis' => 500, 'pids_limit' => 128],
        ],
    ])->assertNoContent();

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($deployment->applied_resources)->toBe(['memory_mb' => 256, 'cpu_millis' => 500, 'pids_limit' => 128])
        ->and($deployment->finalization_deferred)->toBeFalse()
        ->and($deployment->finalization_deferred_reason)->toBeNull()
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->last_deployed_at)->not->toBeNull()
        ->and($deployment->events()->where('type', 'deployment.succeeded')->exists())->toBeTrue()
        ->and(AgentCommand::query()->where('type', AgentCommandType::StopProject)->count())->toBe(0);

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.applied_resources.memory_mb', 256)
        ->assertJsonPath('data.finalization_deferred', false)
        ->assertJsonPath('data.agent_node_id', $node->id);
});

test('the noop runtime completes with a null result and the deployment still succeeds', function (): void {
    $node = flowNode('noop-token');
    ['deployment' => $deployment, 'command' => $command] = deployThroughConsole();
    $headers = flowHeaders($node, 'noop-token');

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])->assertNoContent();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->applied_resources)->toBeNull();
});

test('deferred finalization stops every superseded workload on the same node exactly once', function (): void {
    $node = flowNode('deferred-token');
    $elsewhere = flowNode('elsewhere-token');
    $project = Project::factory()->create([
        'user_id' => User::factory()->create()->id,
        'branch' => 'main',
        'status' => ProjectStatus::Active,
    ]);

    $priorFailed = Deployment::factory()->for($project)->create([
        'sequence' => 1,
        'status' => DeploymentStatus::Failed,
        'agent_node_id' => $node->id,
    ]);
    $priorElsewhere = Deployment::factory()->for($project)->create([
        'sequence' => 2,
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => $elsewhere->id,
    ]);
    $priorOnNode = Deployment::factory()->for($project)->create([
        'sequence' => 3,
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => $node->id,
    ]);

    // Sticky scheduling keeps the redeploy on the node that serves sequence 3.
    ['deployment' => $deployment, 'command' => $command] = deployThroughConsole($project);
    expect($deployment->sequence)->toBe(4)
        ->and($deployment->agent_node_id)->toBe($node->id);

    $headers = flowHeaders($node, 'deferred-token');
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();

    $result = [
        'requested_resources' => ['memory_mb' => 256, 'cpu_millis' => 500, 'pids_limit' => 128],
        'applied_resources' => ['memory_mb' => 256, 'cpu_millis' => 500, 'pids_limit' => 128],
        'finalization_deferred' => true,
        'finalization_deferred_reason' => 'grace_elapsed',
    ];

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => $result])->assertNoContent();
    // Idempotent retry of the same completion must not duplicate anything.
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => $result])->assertNoContent();

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->finalization_deferred)->toBeTrue()
        ->and($deployment->finalization_deferred_reason)->toBe(FinalizationDeferredReason::GraceElapsed)
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);

    $stops = AgentCommand::query()->where('type', AgentCommandType::StopProject)->get();
    expect($stops)->toHaveCount(1);

    $stop = $stops->first();
    expect($stop->deployment_id)->toBe($priorOnNode->id)
        ->and($stop->agent_node_id)->toBe($node->id)
        ->and($stop->status)->toBe(AgentCommandStatus::Pending)
        ->and($stop->idempotency_key)->toBe("deferred-finalization:{$deployment->id}:{$priorOnNode->id}")
        ->and($stop->request_context['reason'])->toBe('finalization_deferred:grace_elapsed')
        ->and($stop->deployment_id)->not->toBe($deployment->id)
        ->and($stop->deployment_id)->not->toBe($priorElsewhere->id)
        ->and($stop->deployment_id)->not->toBe($priorFailed->id);

    $audit = AuditEvent::query()->where('action', 'deployment.finalization_deferred')->sole();
    expect($audit->subject_id)->toBe($deployment->id)
        ->and($audit->metadata['reason'])->toBe('grace_elapsed')
        ->and($audit->metadata['stop_command_ids'])->toBe([$stop->id]);

    // The stale StopProject targets the old workload, so completing it must
    // not flip the freshly deployed project to stopped.
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$stop->id}/claim", [])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$stop->id}/complete", ['result' => ['status' => 'stopped']])->assertNoContent();
    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
});

test('a deferred result wrapped as committed_result is still understood', function (): void {
    $node = flowNode('wrapped-token');
    ['deployment' => $deployment, 'command' => $command] = deployThroughConsole();
    $headers = flowHeaders($node, 'wrapped-token');

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => [
        'committed_result' => ['applied_resources' => ['memory_mb' => 512, 'cpu_millis' => 1000, 'pids_limit' => 256]],
        'finalization_deferred' => true,
        'finalization_deferred_reason' => 'runtime_error',
    ]])->assertNoContent();

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->applied_resources['memory_mb'])->toBe(512)
        ->and($deployment->finalization_deferred_reason)->toBe(FinalizationDeferredReason::RuntimeError);
});

// ─── Failure ─────────────────────────────────────────────────────────────────

test('agent failure classifies the deployment and a late failure after a terminal state does not throw', function (): void {
    $node = flowNode('fail-token');
    ['project' => $project, 'deployment' => $deployment, 'command' => $command] = deployThroughConsole();
    $headers = flowHeaders($node, 'fail-token');

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", phaseEvent('deployment.build.started'))->assertOk();

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/fail", [
        'error_code' => 'runtime_container_failed',
        'error_message' => 'Container exited before becoming ready.',
    ])->assertNoContent();

    $deployment->refresh();
    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_code)->toBe('runtime_container_failed')
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Failed);

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.failure.category', DeploymentFailureCategory::Start->value);

    // Control plane already failed the deployment for another command.
    $second = AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'payload' => [],
    ]);

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$second->id}/fail", [
        'error_code' => 'runtime_timeout',
        'error_message' => 'Timed out.',
    ])->assertNoContent();

    expect($deployment->fresh()->failure_code)->toBe('runtime_container_failed');
});

test('a completion arriving after the control plane closed the deployment is audited, not applied', function (): void {
    $node = flowNode('late-token');
    ['project' => $project, 'deployment' => $deployment, 'command' => $command] = deployThroughConsole();
    $headers = flowHeaders($node, 'late-token');

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $deployment->update(['status' => DeploymentStatus::Failed, 'finished_at' => now(), 'failure_code' => 'command_lease_expired']);

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])->assertNoContent();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($project->fresh()->runtime_status)->not->toBe(RuntimeStatus::Running)
        ->and(AuditEvent::query()->where('action', 'deployment.completion_after_terminal')->exists())->toBeTrue();
});
