<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
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
