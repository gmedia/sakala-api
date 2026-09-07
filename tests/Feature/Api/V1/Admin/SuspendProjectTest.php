<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('admin can suspend a project', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Ready,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertStatus(202);

    $response->assertJsonPath('data.project_id', $project->id);
    $response->assertJsonPath(
        'data.status',
        ProjectStatus::Suspended->value,
    );
    $response->assertJsonPath(
        'data.runtime_status',
        RuntimeStatus::Running->value,
    );
    $response->assertJsonPath(
        'data.command.type',
        AgentCommandType::SleepProject->value,
    );
    $response->assertJsonPath(
        'data.command.status',
        AgentCommandStatus::Pending->value,
    );

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Suspended)
        ->and($project->runtime_status)->toBe(RuntimeStatus::Running);

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::SleepProject)
        ->firstOrFail();

    expect($command->status)
        ->toBe(AgentCommandStatus::Pending)
        ->and($command->deployment_id)
        ->toBe($deployment->id)
        ->and($command->agent_node_id)
        ->toBe($agent->id)
        ->and($command->payload)
        ->toBe([])
        ->and($command->request_context)
        ->toMatchArray([
            'reason' => 'Administrative suspension',
            'actor_type' => User::class,
            'actor_id' => (string) $admin->id,
        ]);
});

test('suspending a project creates an audit event', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Ready,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertStatus(202);

    $audit = AuditEvent::query()
        ->where('action', 'project.suspend_requested')
        ->where('subject_type', Project::class)
        ->where('subject_id', $project->id)
        ->firstOrFail();

    expect($audit->actor_type)
        ->toBe(User::class)
        ->and($audit->actor_id)
        ->toBe((string) $admin->id)
        ->and($audit->metadata['reason'])
        ->toBe('Administrative suspension')
        ->and($audit->metadata['command_id'])
        ->toBeString()
        ->and($audit->metadata['deployment_id'])
        ->toBe($deployment->id)
        ->and($audit->metadata['agent_node_id'])
        ->toBe($agent->id)
        ->and($audit->created_at)
        ->not->toBeNull();
});

test('normal user cannot suspend a project', function (): void {
    $user = User::factory()->create([
        'role' => UserRole::User,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $response = $this->actingAs($user, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertForbidden();

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->runtime_status)->toBe(RuntimeStatus::Running);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::SleepProject)
            ->exists()
    )->toBeFalse();
});

test('suspending a non-existent project returns not found', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $projectId = (string) Str::uuid();

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$projectId}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertNotFound();
});

test('suspending an already suspended project returns conflict', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Suspended,
        'runtime_status' => RuntimeStatus::Stopped,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertStatus(409);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::SleepProject)
            ->count()
    )->toBe(0);
});

test('suspending a project is idempotent with the same idempotency key', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Ready,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $idempotencyKey = (string) Str::uuid();

    $payload = [
        'reason' => 'Administrative suspension',
    ];

    $firstResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            $payload,
        );

    $firstResponse->assertStatus(202);

    $firstCommandId = $firstResponse->json('data.command.id');

    $secondResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            $payload,
        );

    $secondResponse->assertStatus(202);

    $secondCommandId = $secondResponse->json('data.command.id');

    expect($secondCommandId)
        ->toBe($firstCommandId);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count()
    )->toBe(1);

    expect(
        AuditEvent::query()
            ->where('action', 'project.suspend_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);

    $command = AgentCommand::query()
        ->where('id', $firstCommandId)
        ->firstOrFail();

    expect($command->deployment_id)
        ->toBe($deployment->id)
        ->and($command->agent_node_id)
        ->toBe($agent->id)
        ->and($command->payload)
        ->toBe([])
        ->and($command->request_context)
        ->toMatchArray([
            'reason' => 'Administrative suspension',
            'actor_type' => User::class,
            'actor_id' => (string) $admin->id,
        ]);
});

test('suspending a project rejects an idempotency key reused with a different reason', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Ready,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);
    Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $idempotencyKey = (string) Str::uuid();

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            [
                'reason' => 'Administrative suspension',
            ],
        )
        ->assertStatus(202);

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            [
                'reason' => 'Security incident',
            ],
        );

    $response->assertStatus(409);

    expect(
        AgentCommand::query()
            ->where('idempotency_key', $idempotencyKey)
            ->count()
    )->toBe(1);

    expect(
        AuditEvent::query()
            ->where('action', 'project.suspend_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('suspending a project rejects an idempotency key reused by a different actor', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $otherAdmin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Ready,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);
    Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $idempotencyKey = (string) Str::uuid();

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            [
                'reason' => 'Administrative suspension',
            ],
        )
        ->assertStatus(202);

    $response = $this->actingAs($otherAdmin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            [
                'reason' => 'Administrative suspension',
            ],
        );

    $response->assertStatus(409);

    expect(
        AgentCommand::query()
            ->where('idempotency_key', $idempotencyKey)
            ->count()
    )->toBe(1);

    expect(
        AuditEvent::query()
            ->where('action', 'project.suspend_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('suspending a project rejects an idempotency key used by another command', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $idempotencyKey = (string) Str::uuid();

    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Pending,
        'idempotency_key' => $idempotencyKey,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertStatus(409);

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->runtime_status)->toBe(RuntimeStatus::Running);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::SleepProject)
            ->exists()
    )->toBeFalse();
});

test('suspending a project requires a reason', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['reason']);

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->runtime_status)->toBe(RuntimeStatus::Running);
});

test('suspending a project succeeds when the agent is offline', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Offline,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertStatus(202);

    $response->assertJsonPath(
        'data.status',
        ProjectStatus::Suspended->value,
    );

    $response->assertJsonPath(
        'data.runtime_status',
        RuntimeStatus::Running->value,
    );

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::SleepProject)
        ->firstOrFail();

    $project->refresh();

    expect($command->status)
        ->toBe(AgentCommandStatus::Pending)
        ->and($command->deployment_id)
        ->toBe($deployment->id)
        ->and($command->agent_node_id)
        ->toBe($agent->id)
        ->and($command->payload)
        ->toBe([])
        ->and($project->status)
        ->toBe(ProjectStatus::Suspended)
        ->and($project->runtime_status)
        ->toBe(RuntimeStatus::Running);
});

test('suspending a project replays the original response after the project lifecycle changes', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Ready,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);
    Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $idempotencyKey = (string) Str::uuid();

    $firstResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            [
                'reason' => 'Administrative suspension',
            ],
        );

    $firstResponse->assertStatus(202);

    expect($firstResponse->json('data.project_id'))
        ->toBe($project->id)
        ->and($firstResponse->json('data.status'))
        ->toBe(ProjectStatus::Suspended->value)
        ->and($firstResponse->json('data.runtime_status'))
        ->toBe(RuntimeStatus::Running->value)
        ->and($firstResponse->json('data.command.status'))
        ->toBe(AgentCommandStatus::Pending->value);

    $command = AgentCommand::query()
        ->where('idempotency_key', $idempotencyKey)
        ->firstOrFail();

    expect($command->response_context)->toMatchArray([
        'project_status' => ProjectStatus::Suspended->value,
        'runtime_status' => RuntimeStatus::Running->value,
    ]);

    /*
     * Original suspend command succeeds.
     */
    $command->update([
        'status' => AgentCommandStatus::Succeeded,
    ]);

    /*
     * Sleep completed.
     */
    $project->update([
        'status' => ProjectStatus::Suspended,
        'runtime_status' => RuntimeStatus::Stopped,
    ]);

    /*
     * Project later enters another lifecycle state.
     */
    $project->update([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $secondResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            [
                'reason' => 'Administrative suspension',
            ],
        );

    $secondResponse->assertStatus(202);

    expect($secondResponse->json('data.project_id'))
        ->toBe($project->id)
        ->and($secondResponse->json('data.status'))
        ->toBe(ProjectStatus::Suspended->value)
        ->and($secondResponse->json('data.runtime_status'))
        ->toBe(RuntimeStatus::Running->value)
        ->and($secondResponse->json('data.command.id'))
        ->toBe($command->id)
        ->and($secondResponse->json('data.command.status'))
        ->toBe(AgentCommandStatus::Succeeded->value);

    expect(
        AgentCommand::query()
            ->where('idempotency_key', $idempotencyKey)
            ->count(),
    )->toBe(1);
});

test('suspends a project using the succeeded deployment as the live target', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $agent = AgentNode::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertAccepted();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::SleepProject)
        ->firstOrFail();

    expect($command->deployment_id)->toBe($deployment->id);
    expect($command->agent_node_id)->toBe($agent->id);
});

test('targets the live deployment when a newer deployment is still in flight', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $agent = AgentNode::factory()->create();

    $liveDeployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);

    $candidateDeployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Queued,
        'sequence' => 2,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertAccepted();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::SleepProject)
        ->firstOrFail();

    expect($command->deployment_id)
        ->toBe($liveDeployment->id)
        ->not->toBe($candidateDeployment->id);

    expect($command->agent_node_id)
        ->toBe($agent->id);
});

test('suspends a project without a live deployment target', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Stopped,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertAccepted();

    expect($project->refresh()->status)
        ->toBe(ProjectStatus::Suspended);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::SleepProject)
            ->exists(),
    )->toBeFalse();
});

test('admin can suspend project without live workload', function () {
    $project = Project::factory()->create();

    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', 'suspend-no-workload-1')
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            ['reason' => 'Emergency maintenance'],
        );

    $response->assertAccepted();

    expect($project->refresh()->status)
        ->toBe(ProjectStatus::Suspended);

    expect(AgentCommand::query()->count())
        ->toBe(0);

    expect(ProjectControlRequest::query()
        ->where('idempotency_key', 'suspend-no-workload-1')
        ->count()
    )->toBe(1);
});

test('exact retry of suspend without workload returns the original result', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $key = 'suspend-retry-no-workload-1';

    $payload = [
        'reason' => 'Emergency maintenance',
    ];

    $firstResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $key)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            $payload,
        );

    $firstResponse->assertAccepted();

    $retryResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $key)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            $payload,
        );

    $retryResponse
        ->assertAccepted()
        ->assertJson($firstResponse->json());

    expect(ProjectControlRequest::query()
        ->where('idempotency_key', $key)
        ->count()
    )->toBe(1);
});

test('reusing suspend idempotency key with a different reason returns conflict', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $key = 'suspend-reason-conflict-1';

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $key)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            ['reason' => 'Emergency maintenance'],
        )
        ->assertAccepted();

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $key)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            ['reason' => 'Different reason'],
        )
        ->assertConflict();
});

test('reusing suspend idempotency key with a different actor returns conflict', function () {
    $project = Project::factory()->create();

    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $otherAdmin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $key = 'suspend-actor-conflict-1';

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $key)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            ['reason' => 'Emergency maintenance'],
        )
        ->assertAccepted();

    $this->actingAs($otherAdmin, 'web')
        ->withHeader('Idempotency-Key', $key)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            ['reason' => 'Emergency maintenance'],
        )
        ->assertConflict();
});

test('idempotent suspend retry creates only one audit event', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);
    $key = 'suspend-audit-1';

    $payload = [
        'reason' => 'Emergency maintenance',
    ];

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $key)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            $payload,
        )
        ->assertAccepted();

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $key)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            $payload,
        )
        ->assertAccepted();

    expect(AuditEvent::query()
        ->where('action', 'project.suspend_requested')
        ->where('subject_type', Project::class)
        ->where('subject_id', $project->id)
        ->count()
    )->toBe(1);
});

test('admin can suspend project without active deployment', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertAccepted();

    expect($project->refresh()->status)
        ->toBe(ProjectStatus::Suspended);

    expect(
        ProjectControlRequest::query()
            ->where('project_id', $project->id)
            ->count()
    )->toBe(1);

    $controlRequest = ProjectControlRequest::query()
        ->where('project_id', $project->id)
        ->first();

    expect($controlRequest)->not->toBeNull()
        ->and($controlRequest->agent_command_id)->toBeNull();

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->count()
    )->toBe(0);

    expect(
        AuditEvent::query()
            ->where('action', 'project.suspend_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('admin can retry suspend without active deployment using the same idempotency key', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
    ]);

    $headers = [
        'Idempotency-Key' => 'suspend-project-001',
    ];

    $payload = [
        'reason' => 'Administrative suspension',
    ];

    $firstResponse = $this->actingAs($admin, 'web')
        ->withHeaders($headers)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            $payload,
        );

    $firstResponse->assertAccepted();

    $secondResponse = $this->actingAs($admin, 'web')
        ->withHeaders($headers)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/suspend",
            $payload,
        );

    $secondResponse->assertAccepted();

    expect(
        ProjectControlRequest::query()
            ->where('idempotency_key', 'suspend-project-001')
            ->count()
    )->toBe(1);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->count()
    )->toBe(0);

    expect(
        AuditEvent::query()
            ->where('action', 'project.suspend_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);

    expect($project->refresh()->status)
        ->toBe(ProjectStatus::Suspended);
});

test('admin can suspend project with active deployment', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => AgentNode::factory()->create()->id,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertAccepted();

    expect($project->refresh()->status)
        ->toBe(ProjectStatus::Suspended);

    $controlRequest = ProjectControlRequest::query()
        ->where('project_id', $project->id)
        ->first();

    expect($controlRequest)->not->toBeNull()
        ->and($controlRequest->agent_command_id)->not->toBeNull();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->first();

    expect($command)->not->toBeNull()
        ->and($command->type)->toBe(AgentCommandType::SleepProject)
        ->and($command->deployment_id)->toBe($deployment->id);

    expect(
        AuditEvent::query()
            ->where('action', 'project.suspend_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('admin cannot reuse suspend idempotency key with a different reason', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', 'suspend-project-002')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertAccepted();

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', 'suspend-project-002')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", [
            'reason' => 'Security incident',
        ]);

    $response->assertConflict();

    expect(
        ProjectControlRequest::query()
            ->where('idempotency_key', 'suspend-project-002')
            ->count()
    )->toBe(1);

    expect(
        AuditEvent::query()
            ->where('action', 'project.suspend_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('admin cannot reuse suspend idempotency key for another project', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $projectA = Project::factory()->create([
        'status' => ProjectStatus::Active,
    ]);

    $projectB = Project::factory()->create([
        'status' => ProjectStatus::Active,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', 'suspend-project-003')
        ->postJson("/api/v1/admin/projects/{$projectA->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertAccepted();

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', 'suspend-project-003')
        ->postJson("/api/v1/admin/projects/{$projectB->id}/suspend", [
            'reason' => 'Administrative suspension',
        ]);

    $response->assertConflict();

    expect(
        ProjectControlRequest::query()
            ->where('idempotency_key', 'suspend-project-003')
            ->count()
    )->toBe(1);
});
