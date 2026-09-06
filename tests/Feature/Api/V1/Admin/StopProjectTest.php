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
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('admin can stop a project', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertSuccessful();

    $response->assertJsonPath('data.project_id', $project->id);
    $response->assertJsonPath(
        'data.runtime_status',
        RuntimeStatus::Stopped->value,
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
        ->and($project->runtime_status)->toBe(RuntimeStatus::Stopped);

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::StopProject)
        ->first();

    expect($command)->not->toBeNull()
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($command->payload['reason'])->toBe('Emergency incident');
});

test('stopping a project creates an audit event', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    $before = now();

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertSuccessful();

    $audit = AuditEvent::query()
        ->where('action', 'project.stopped')
        ->where('subject_type', Project::class)
        ->where('subject_id', $project->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe(User::class)
        ->and((int) $audit->actor_id)->toBe($admin->id)
        ->and($audit->metadata['reason'])->toBe('Emergency incident')
        ->and($audit->created_at)->not->toBeNull();
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

    $idempotencyKey = (string) Str::uuid();

    $firstResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $firstResponse->assertSuccessful();

    $firstCommandId = $firstResponse->json('data.command.id');

    $secondResponse = $this->actingAs($admin, 'web')
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $secondResponse->assertSuccessful();

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
            ->where('action', 'project.stopped')
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

    $response = $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/stop", [
            'reason' => 'Emergency incident',
        ]);

    $response->assertSuccessful();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::StopProject)
        ->firstOrFail();

    expect($command->status)
        ->toBe(AgentCommandStatus::Pending);
});
