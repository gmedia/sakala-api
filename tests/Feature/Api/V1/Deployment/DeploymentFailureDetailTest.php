<?php

declare(strict_types=1);

use App\Actions\Agent\ExpireAgentCommandsAction;
use App\Actions\Deployment\PruneDeploymentLogsAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{AgentNode, User, Project, Deployment, AgentCommand} */
function failingAttempt(DeploymentStatus $status = DeploymentStatus::Building): array
{
    $node = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'token_hash' => hash_hmac('sha256', 'detail-token', (string) config('app.key')),
    ]);
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => $status,
        'sequence' => 1,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);

    return [$node, $user, $project, $deployment, $command];
}

function failAttempt(object $test, AgentNode $node, AgentCommand $command, string $message, string $code = 'runtime_build_failed'): void
{
    $test->withHeaders([
        'Authorization' => 'Bearer detail-token',
        'X-Agent-Id' => $node->agent_id,
    ])->postJson("/api/agent/v1/commands/{$command->id}/fail", [
        'error_code' => $code,
        'error_message' => $message,
    ])->assertNoContent();
}

test('the failure exposes the agent message, stage, and who can fix it', function (): void {
    [$node, $user, $project, $deployment, $command] = failingAttempt();

    failAttempt($this, $node, $command, 'Aplikasi tidak listen di port 3000.', 'runtime_app_not_listening');

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.failure.code', 'runtime_app_not_listening')
        ->assertJsonPath('data.failure.category', 'health')
        ->assertJsonPath('data.failure.detail', 'Aplikasi tidak listen di port 3000.')
        ->assertJsonPath('data.failure.stage', 'building')
        ->assertJsonPath('data.failure.user_fixable', true);
});

test('a failure before any phase event still has a stage', function (): void {
    [$node, $user, $project, $deployment, $command] = failingAttempt(DeploymentStatus::Queued);

    failAttempt($this, $node, $command, 'credential unavailable', 'repository_credential_unavailable');

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertJsonPath('data.failure.stage', 'queued');
});

test('secrets in the agent message are redacted before anything is stored', function (): void {
    [$node, , , $deployment, $command] = failingAttempt();

    failAttempt($this, $node, $command, 'clone https://user:hunter2@github.com/a/b failed, token=abc123 and ghs_supersecretvalue');

    foreach ([$deployment->fresh()->failure_detail, $command->fresh()->error_message] as $stored) {
        expect($stored)
            ->not->toContain('hunter2')
            ->not->toContain('abc123')
            ->not->toContain('ghs_supersecretvalue')
            ->toContain('[REDACTED]');
    }
});

test('the detail is bounded to 500 characters, counted in characters', function (): void {
    [$node, , , $deployment, $command] = failingAttempt();

    failAttempt($this, $node, $command, str_repeat('é', 900));

    expect(mb_strlen((string) $deployment->fresh()->failure_detail))->toBe(500)
        ->and(mb_strlen((string) $command->fresh()->error_message))->toBe(900);
});

test('a secret straddling the bound is redacted, not cut in half', function (): void {
    [$node, , , $deployment, $command] = failingAttempt();

    // Bounding first would cut the URL before its `@`, the shape the redaction
    // recognises, and leave the start of the credential in the detail.
    failAttempt($this, $node, $command, str_repeat('x ', 245).'https://user:hunter2hunter2hunter2@github.com/a/b');

    expect($deployment->fresh()->failure_detail)->not->toContain('hunter2')
        ->and($command->fresh()->error_message)->not->toContain('hunter2');
});

test('control characters never reach the detail', function (): void {
    [$node, , , $deployment, $command] = failingAttempt();

    failAttempt($this, $node, $command, "build\u{202E} failed\x07 badly");

    expect($deployment->fresh()->failure_detail)->toBe('build failed badly');
});

test('a closure by the control plane has a stage but no agent detail', function (): void {
    [, $user, $project, $deployment, $command] = failingAttempt(DeploymentStatus::Deploying);
    $command->update(['lease_expires_at' => now()->subMinute()]);

    app(ExpireAgentCommandsAction::class)->handle();

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertJsonPath('data.failure.code', 'command_lease_expired')
        ->assertJsonPath('data.failure.user_fixable', false)
        ->assertJsonPath('data.failure.detail', null)
        ->assertJsonPath('data.failure.stage', 'deploying');
});

test('detail and stage keep their meaning after the timeline is pruned', function (): void {
    [$node, $user, $project, $deployment, $command] = failingAttempt();
    DeploymentEvent::factory()->for($deployment)->create(['sequence' => 1]);

    failAttempt($this, $node, $command, 'npm run build exited with status 1');

    $deployment->fresh()->forceFill(['finished_at' => now()->subDays(8)])->save();

    $result = app(PruneDeploymentLogsAction::class)->handle(days: 7);

    expect($result->prunedEventsCount)->toBeGreaterThan(0)
        ->and(DeploymentEvent::query()->where('deployment_id', $deployment->id)->count())->toBe(0);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertJsonPath('data.failure.detail', 'npm run build exited with status 1')
        ->assertJsonPath('data.failure.stage', 'building');
});

test('a repeated fail report does not rewrite the stored detail', function (): void {
    [$node, , , $deployment, $command] = failingAttempt();

    failAttempt($this, $node, $command, 'first message');
    failAttempt($this, $node, $command, 'second message');

    expect($deployment->fresh()->failure_detail)->toBe('first message');
});

test('a deployment failed before this change reads detail and stage as null', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'sequence' => 1,
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertJsonPath('data.failure.detail', null)
        ->assertJsonPath('data.failure.stage', null)
        ->assertJsonPath('data.failure.code', 'runtime_build_failed');
});

test('another user cannot read the failure detail', function (): void {
    [$node, , $project, $deployment, $command] = failingAttempt();

    failAttempt($this, $node, $command, 'private diagnostics');

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertForbidden();
});
