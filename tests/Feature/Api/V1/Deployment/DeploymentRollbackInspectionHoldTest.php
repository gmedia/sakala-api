<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectInspectionStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Deployment\DeploymentInspectionHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Http::fake(['api.github.com/repos/*/commits*' => Http::response([['sha' => ROLLBACK_NEW_COMMIT, 'commit' => ['message' => 'feat: head']]], 200)]);
    config(['sakala.deployments.inspection_wait_seconds' => 900]);
});

/** A re-inspection is running and no port is configured: what makes a normal deployment wait. */
function holdPendingInspection(Project $project): Project
{
    $project->update(['configured_port' => null, 'inspection_status' => ProjectInspectionStatus::Pending]);

    return $project->fresh();
}

function holdQueueStateOf(Project $project, Deployment $deployment): ?string
{
    return test()->actingAs($project->workspace->members()->firstOrFail()->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertOk()
        ->json('data.queue_state');
}

/** @return list<string> */
function holdPolled(AgentNode $node): array
{
    return array_column(test()->withHeaders(databaseNodeHeaders($node))->getJson('/api/agent/v1/commands')->assertOk()->json('data'), 'id');
}

test('a rollback is never held for the inspection: it runs on the recorded port right away', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node, 'old' => $old] = rollbackTwoVersions();
    $project = holdPendingInspection($project);

    $response = $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))
        ->assertCreated()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.queue_state', 'assigned');

    $rollback = Deployment::query()->findOrFail($response->json('data.id'));
    $command = AgentCommand::query()->where('deployment_id', $rollback->id)->sole();

    expect($rollback->awaiting_inspection_until)->toBeNull()
        ->and($rollback->container_port)->toBe(3001)
        ->and($command->payload['container_port'])->toBe(3001)
        ->and(holdPolled($node))->toContain($command->id);

    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();

    // A settle sweep finds nothing to do and does not touch the recorded port.
    app(DeploymentInspectionHold::class)->settleProject($project->id);
    expect($rollback->fresh()->container_port)->toBe(3001);
});

test('a normal deployment in the same state is still held, and releases and claims once the inspection finishes', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node] = rollbackTwoVersions();
    $project = holdPendingInspection($project);

    $id = $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated()
        ->assertJsonPath('data.queue_state', 'waiting_for_inspection')
        ->json('data.id');
    $deployment = Deployment::query()->findOrFail($id);
    $command = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->awaiting_inspection_until)->not->toBeNull()
        ->and(holdPolled($node))->not->toContain($command->id);
    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertStatus(409);

    $project->update(['inspection_status' => ProjectInspectionStatus::Succeeded, 'detected_port' => 8080]);
    app(DeploymentInspectionHold::class)->settleProject($project->id);

    expect($deployment->fresh()->awaiting_inspection_until)->toBeNull()
        ->and($deployment->fresh()->container_port)->toBe(8080)
        ->and(holdQueueStateOf($project, $deployment))->toBe('assigned')
        ->and(holdPolled($node))->toContain($command->id);
    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
});

test('a password rotation redeploy of a live rollback is not held either', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node, 'old' => $old] = rollbackTwoVersions();
    $database = databaseIn($team['workspace'], $node);
    attachDatabase($database, $project->fresh());
    $rollback = Deployment::query()->findOrFail($this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))->json('data.id'));
    rollbackSucceed($rollback);
    holdPendingInspection($project->fresh());

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/rotate-password", ['redeploy_attached' => true])
        ->assertAccepted();
    $rotation = databaseCommand($database, AgentCommandType::RotateDatabasePassword);
    $rotation->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$rotation->id}/complete", ['result' => null])->assertNoContent();

    $redeploy = Deployment::query()->where('settings_source_deployment_id', $old->id)->where('id', '!=', $rollback->id)->sole();

    expect($redeploy->awaiting_inspection_until)->toBeNull()
        ->and($redeploy->container_port)->toBe(3001)
        ->and($redeploy->status)->toBe(DeploymentStatus::Queued);
});

test('restart is not held by an inspection, and waits out a held deployment as busy', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node] = rollbackTwoVersions();
    $project = holdPendingInspection($project);

    // No held deployment: the restart goes to the node at once.
    $commandId = $this->actingAs($team['member'], 'web')->postJson("/api/v1/app/projects/{$project->id}/restart")
        ->assertAccepted()
        ->json('data.command.id');
    expect(holdPolled($node))->toContain($commandId);
    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$commandId}/claim", [])->assertOk();
    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$commandId}/complete", ['result' => null])->assertNoContent();

    // A held deployment is queued, so the project is changing.
    $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertJsonPath('data.queue_state', 'waiting_for_inspection');

    $this->actingAs($team['member'], 'web')->postJson("/api/v1/app/projects/{$project->id}/restart")
        ->assertStatus(409)
        ->assertJsonPath('code', 'PROJECT_RUNTIME_BUSY');
});
