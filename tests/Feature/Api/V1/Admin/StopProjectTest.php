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

test('admin can stop a project', function (): void {
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
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(202);

    $response->assertJsonPath('data.project_id', $project->id);
    $response->assertJsonPath(
        'data.runtime_status',
        RuntimeStatus::Running->value,
    );
    $response->assertJsonPath(
        'data.command.type',
        AgentCommandType::StopProject->value,
    );
    $response->assertJsonPath(
        'data.command.status',
        AgentCommandStatus::Pending->value,
    );

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->runtime_status)->toBe(RuntimeStatus::Running);

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::StopProject)
        ->firstOrFail();

    expect($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($command->deployment_id)->toBe($deployment->id)
        ->and($command->agent_node_id)->toBe($agent->id)
        ->and($command->payload)->toBe([]);
});

test('stopping a project creates an audit event', function (): void {
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
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(202);

    $audit = AuditEvent::query()
        ->where('action', 'project.stop_requested')
        ->where('subject_type', Project::class)
        ->where('subject_id', $project->id)
        ->firstOrFail();

    expect($audit->actor_id)->toBe((string) $admin->id)
        ->and($audit->metadata['reason'])->toBe('Emergency incident');
});

test('normal user cannot stop a project', function (): void {
    $user = User::factory()->create([
        'role' => UserRole::User,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $response = $this->actingAs($user, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertForbidden();

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->runtime_status)->toBe(RuntimeStatus::Running);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::StopProject)
            ->exists()
    )->toBeFalse();
});

test('stopping a non-existent project returns not found', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $projectId = (string) Str::uuid();

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$projectId}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertNotFound();
});

test('stopping an already stopped project returns conflict', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Stopped,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(409);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::StopProject)
            ->count()
    )->toBe(0);
});

test('stopping a project is idempotent with the same idempotency key', function (): void {
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

    Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $idempotencyKey = (string) Str::uuid();

    $firstResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $firstResponse->assertStatus(202);

    $firstCommandId = $firstResponse->json('data.command.id');

    $secondResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $secondResponse->assertStatus(202);

    $secondCommandId = $secondResponse->json('data.command.id');

    expect($secondCommandId)->toBe($firstCommandId);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count()
    )->toBe(1);

    expect(
        AuditEvent::query()
            ->where('action', 'project.stop_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('stopping a project rejects an idempotency key used by another command', function (): void {
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
        'type' => AgentCommandType::SleepProject,
        'status' => AgentCommandStatus::Pending,
        'idempotency_key' => $idempotencyKey,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(409);

    $project->refresh();

    expect($project->runtime_status)->toBe(RuntimeStatus::Running);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::StopProject)
            ->exists()
    )->toBeFalse();
});

test('stopping a project requires a reason', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['reason']);

    $project->refresh();

    expect($project->runtime_status)->toBe(RuntimeStatus::Running);
});

test('stopping a project succeeds when the agent is offline', function (): void {
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
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(202);

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::StopProject)
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
        ->and($project->runtime_status)
        ->toBe(RuntimeStatus::Running);
});

test('stopping a project targets the latest active deployment and its owning agent', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $oldAgent = AgentNode::factory()->create();
    $currentAgent = AgentNode::factory()->create();

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $oldDeployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $oldAgent->id,
        'sequence' => 1,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $currentDeployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $currentAgent->id,
        'sequence' => 2,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(202);

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::StopProject)
        ->firstOrFail();

    expect($command->deployment_id)->toBe($currentDeployment->id)
        ->and($command->agent_node_id)->toBe($currentAgent->id)
        ->and($command->deployment_id)->not->toBe($oldDeployment->id)
        ->and($command->agent_node_id)->not->toBe($oldAgent->id);
});

test('stopping a project rejects when a stop command is already in progress', function (): void {
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

    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Pending,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(409);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::StopProject)
            ->count()
    )->toBe(1);

    $project->refresh();

    expect($project->runtime_status)
        ->toBe(RuntimeStatus::Running);
});

test('stopping a project rejects another stop while a stop command is pending', function (): void {
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

    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
        'type' => AgentCommandType::StopProject,
        'status' => AgentCommandStatus::Pending,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(409);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::StopProject)
            ->count()
    )->toBe(1);

    expect($project->fresh()->runtime_status)
        ->toBe(RuntimeStatus::Running);
});

test('stopping a project rejects an idempotency key reused with a different reason', function (): void {
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
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ])
        ->assertStatus(202);

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Scheduled maintenance',
        ]);

    $response->assertStatus(409);

    expect(
        AgentCommand::query()
            ->where('idempotency_key', $idempotencyKey)
            ->count()
    )->toBe(1);

    expect(
        AuditEvent::query()
            ->where('action', 'project.stop_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('stopping a project rejects an idempotency key reused by a different actor', function (): void {
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
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ])
        ->assertStatus(202);

    $response = $this->actingAs($otherAdmin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(409);

    expect(
        AgentCommand::query()
            ->where('idempotency_key', $idempotencyKey)
            ->count()
    )->toBe(1);

    expect(
        AuditEvent::query()
            ->where('action', 'project.stop_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('stopping a project stores request context separately from runtime payload', function (): void {
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

    $response = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', 'stop-context-test')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertStatus(202);

    $command = AgentCommand::query()
        ->where('idempotency_key', 'stop-context-test')
        ->firstOrFail();

    expect($command->payload)->toBe([])
        ->and($command->request_context)->toMatchArray([
            'reason' => 'Emergency incident',
            'actor_type' => User::class,
            'actor_id' => (string) $admin->id,
        ]);
});

test('stopping a project replays the original response after the project lifecycle changes', function (): void {
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
            "/api/v1/admin/projects/{$project->id}/stop",
            [
                'reason' => 'Emergency incident',
            ],
        );

    $firstResponse->assertStatus(202);

    expect($firstResponse->json('data.status'))
        ->toBe(ProjectStatus::Active->value)
        ->and($firstResponse->json('data.runtime_status'))
        ->toBe(RuntimeStatus::Running->value)
        ->and($firstResponse->json('data.command.status'))
        ->toBe(AgentCommandStatus::Pending->value);

    $command = AgentCommand::query()
        ->where('idempotency_key', $idempotencyKey)
        ->firstOrFail();

    /*
     * Original command succeeds.
     */
    $command->update([
        'status' => AgentCommandStatus::Succeeded,
    ]);

    $project->update([
        'runtime_status' => RuntimeStatus::Stopped,
    ]);

    /*
     * Project is subsequently started again through another
     * lifecycle operation.
     */
    $project->update([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $secondResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/stop",
            [
                'reason' => 'Emergency incident',
            ],
        );

    $secondResponse->assertStatus(202);

    /*
     * The command status is current, but the project state comes
     * from the original request snapshot.
     */
    expect($secondResponse->json('data.project_id'))
        ->toBe($project->id)
        ->and($secondResponse->json('data.status'))
        ->toBe(ProjectStatus::Active->value)
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

test('rejects an empty idempotency key', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create();

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', '')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency stop',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('Idempotency-Key');
});

test('rejects a whitespace only idempotency key', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create();

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', '   ')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency stop',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('Idempotency-Key');
});

test('rejects an oversized idempotency key', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create();

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', str_repeat('a', 192))
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency stop',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('Idempotency-Key');
});

test('trims a valid idempotency key', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create();

    $agent = AgentNode::factory()->create([
        'status' => AgentNodeStatus::Ready,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Succeeded,
    ]);

    $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', '  emergency-stop-1  ')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency stop',
        ])
        ->assertAccepted();

    expect(
        AgentCommand::query()
            ->where('idempotency_key', 'emergency-stop-1')
            ->exists()
    )->toBeTrue();
});

test('stops a project using the succeeded deployment as the live target', function () {
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
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency stop',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertAccepted();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::StopProject)
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

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Queued,
        'sequence' => 2,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency stop',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertAccepted();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::StopProject)
        ->firstOrFail();

    expect($command->deployment_id)->toBe($liveDeployment->id);
    expect($command->agent_node_id)->toBe($agent->id);
});

test('targets the succeeded deployment when a newer deployment is still in flight', function () {
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

    Deployment::factory()->create([
        'project_id' => $project->id,
        'agent_node_id' => $agent->id,
        'status' => DeploymentStatus::Queued,
        'sequence' => 2,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency stop',
            'idempotency_key' => (string) Str::uuid(),
        ]);

    $response->assertAccepted();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::StopProject)
        ->firstOrFail();

    expect($command->deployment_id)
        ->toBe($liveDeployment->id);

    expect($command->agent_node_id)
        ->toBe($agent->id);
});

test('admin can stop project with active deployment', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $agentNode = AgentNode::factory()->create();

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => $agentNode->id,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Administrative stop',
        ]);

    $response->assertAccepted();

    $controlRequest = ProjectControlRequest::query()
        ->where('project_id', $project->id)
        ->first();

    expect($controlRequest)->not->toBeNull()
        ->and($controlRequest->agent_command_id)->not->toBeNull();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->first();

    expect($command)->not->toBeNull()
        ->and($command->type)->toBe(AgentCommandType::StopProject)
        ->and($command->deployment_id)->toBe($deployment->id);

    expect(
        AuditEvent::query()
            ->where('action', 'project.stop_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});

test('admin can retry stop using the same idempotency key', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => AgentNode::factory()->create()->id,
    ]);

    $payload = [
        'reason' => 'Administrative stop',
    ];

    $firstResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', 'stop-project-001')
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/stop",
            $payload,
        );

    $firstResponse->assertAccepted();

    $secondResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', 'stop-project-001')
        ->postJson(
            "/api/v1/admin/projects/{$project->id}/stop",
            $payload,
        );

    $secondResponse->assertAccepted();

    expect(
        ProjectControlRequest::query()
            ->where('idempotency_key', 'stop-project-001')
            ->count()
    )->toBe(1);

    expect(
        AgentCommand::query()
            ->where('project_id', $project->id)
            ->count()
    )->toBe(1);

    expect(
        AuditEvent::query()
            ->where('action', 'project.stop_requested')
            ->where('subject_id', $project->id)
            ->count()
    )->toBe(1);
});
