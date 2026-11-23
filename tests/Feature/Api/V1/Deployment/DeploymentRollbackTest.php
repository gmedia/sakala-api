<?php

declare(strict_types=1);

use App\Actions\Deployment\RecordRollbackAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentBuildFallbackReason;
use App\Enums\DeploymentBuildMode;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ProjectStatus;
use App\Enums\RollbackIneligibleReason;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    // A rollback names its commit: GitHub is never asked for a branch head.
    Http::preventStrayRequests();
});

// ─── Authorization and tenancy ───────────────────────────────────────────────

test('owners, members, and admins roll back; viewers and outsiders get 403', function (string $role, int $status): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();

    $response = $this->actingAs($team[$role], 'web')->postJson(rollbackUrl($project, $old))->assertStatus($status);

    if ($status === 403) {
        expect($response->json('code'))->toBeNull()
            ->and(Deployment::query()->where('trigger', DeploymentTrigger::Rollback)->exists())->toBeFalse();
    }
})->with([
    'owner' => ['owner', 201],
    'member' => ['member', 201],
    'admin' => ['admin', 201],
    'viewer' => ['viewer', 403],
    'outsider' => ['outsider', 403],
]);

test('a deployment of another project is not found through this project', function (): void {
    ['team' => $team, 'project' => $project] = rollbackTwoVersions();
    ['project' => $other, 'old' => $otherOld] = rollbackTwoVersions();

    $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $otherOld))->assertNotFound();

    expect(Deployment::query()->where('trigger', DeploymentTrigger::Rollback)->exists())->toBeFalse()
        ->and($other->fresh()->auto_deploy)->toBeTrue();
});

test('a suspended project tells its members why and keeps outsiders at a plain 403', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();
    $project->update(['status' => ProjectStatus::Suspended]);

    $this->actingAs($team['member'], 'web')->postJson(rollbackUrl($project, $old))
        ->assertStatus(409)
        ->assertJsonPath('code', 'PROJECT_SUSPENDED');

    $outsider = $this->actingAs($team['outsider'], 'web')->postJson(rollbackUrl($project, $old))->assertForbidden();
    expect($outsider->json('code'))->toBeNull();
});

test('a rollback needs the current legal documents like a deploy', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();
    $team['member']->legalAcceptances()->delete();

    $this->actingAs($team['member'], 'web')->postJson(rollbackUrl($project, $old))
        ->assertForbidden()
        ->assertJsonPath('code', 'LEGAL_ACCEPTANCE_REQUIRED');
});

// ─── What a rollback runs ────────────────────────────────────────────────────

test('a rollback runs the target commit and recorded settings with the current variables and reuses its image', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node, 'old' => $old] = rollbackTwoVersions();

    $response = $this->actingAs($team['member'], 'web')->postJson(rollbackUrl($project, $old))
        ->assertCreated()
        ->assertJsonPath('data.trigger', 'rollback')
        ->assertJsonPath('data.commit_sha', ROLLBACK_OLD_COMMIT)
        ->assertJsonPath('data.rollback', ['target_deployment_id' => $old->id, 'target_sequence' => $old->sequence])
        ->assertJsonPath('data.triggered_by', ['kind' => 'user', 'name' => $team['member']->name, 'user_id' => $team['member']->id])
        ->assertJsonPath('data.build', [
            'mode' => 'reused',
            'image_source_deployment_id' => $old->id,
            'image_source_sequence' => $old->sequence,
            'fallback_reason' => null,
        ]);

    $rollback = Deployment::query()->findOrFail($response->json('data.id'));
    $payload = rollbackPayload($rollback);

    expect($rollback->agent_node_id)->toBe($node->id)
        ->and($rollback->rollback_target_deployment_id)->toBe($old->id)
        ->and($rollback->settings_source_deployment_id)->toBe($old->id)
        ->and($rollback->build_fingerprint)->toBe($old->build_fingerprint)
        ->and($rollback->config_fingerprint)->toBe($project->fresh()->deploymentConfigFingerprint())
        ->and($rollback->container_port)->toBe(3001)
        ->and($payload['commit_sha'])->toBe(ROLLBACK_OLD_COMMIT)
        ->and($payload['reuse_image_from_deployment_id'])->toBe($old->id)
        // The target's settings, not the project's current ones.
        ->and($payload['build_command'])->toBe('npm run build:v1')
        ->and($payload['start_command'])->toBe('node v1.js')
        ->and($payload['release_command'])->toBe('node migrate-v1.js')
        ->and($payload['root_directory'])->toBe('apps/web')
        // The project's current variables.
        ->and(array_keys($payload['environment']))->toContain('APP_NAME', 'FEATURE_FLAG')
        ->and(Crypt::decryptString($payload['environment']['FEATURE_FLAG']))->toBe('on');

    // Once live, the project does not ask for a redeploy.
    rollbackSucceed($rollback);
    expect($project->fresh()->requiresRedeploy())->toBeFalse();
});

test('a rollback builds the target commit when the node lacks image-reuse', function (): void {
    $capabilities = array_values(array_diff(ROLLBACK_CAPABILITIES, ['image-reuse']));
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions($capabilities);

    $response = $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))
        ->assertCreated()
        ->assertJsonPath('data.build.mode', 'built')
        ->assertJsonPath('data.build.image_source_deployment_id', null);

    $payload = rollbackPayload(Deployment::query()->findOrFail($response->json('data.id')));

    expect($payload)->not->toHaveKey('reuse_image_from_deployment_id')
        ->and($payload['commit_sha'])->toBe(ROLLBACK_OLD_COMMIT)
        ->and($payload['build_command'])->toBe('npm run build:v1');
});

test('a rollback builds when the target ran on another node than the one selected', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();
    $old->update(['agent_node_id' => rollbackNode()->id]);

    $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))
        ->assertCreated()
        ->assertJsonPath('data.build.mode', 'built');
});

test('the agent falling back to a build shows on the rollback like any planned reuse', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node, 'old' => $old] = rollbackTwoVersions();
    $rollback = Deployment::query()->findOrFail(
        $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))->assertCreated()->json('data.id'),
    );
    $command = AgentCommand::query()->where('deployment_id', $rollback->id)->sole();

    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();
    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/events", [
            'type' => 'deployment.image.reuse_skipped',
            'level' => 'warning',
            'message' => 'The image is gone; building.',
            'metadata' => ['source_deployment_id' => $old->id, 'reason' => 'image_missing'],
            'occurred_at' => now()->toIso8601String(),
        ])
        ->assertOk();

    $rollback->refresh();
    expect($rollback->build_mode)->toBe(DeploymentBuildMode::Built)
        ->and($rollback->build_fallback_reason)->toBe(DeploymentBuildFallbackReason::ImageMissing)
        ->and($rollback->image_source_deployment_id)->toBe($old->id)
        ->and($rollback->trigger)->toBe(DeploymentTrigger::Rollback);
});

// ─── Refusals ────────────────────────────────────────────────────────────────

test('a deployment that cannot be gone back to answers 409 with its reason', function (string $case, RollbackIneligibleReason $reason): void {
    ['team' => $team, 'project' => $project, 'old' => $old, 'live' => $live] = rollbackTwoVersions();

    $target = match ($case) {
        'failed' => Deployment::factory()->for($project)->create(['sequence' => 10, 'branch' => 'main', 'commit_sha' => ROLLBACK_OLD_COMMIT, 'status' => DeploymentStatus::Failed]),
        'active' => $live,
        'no commit' => Deployment::factory()->for($project)->create(['sequence' => 10, 'branch' => 'main', 'commit_sha' => null, 'status' => DeploymentStatus::Succeeded]),
        'other branch' => tap($old, fn () => $project->update(['branch' => 'develop'])),
        'no recorded settings' => Deployment::factory()->for($project)->create(['sequence' => 10, 'branch' => 'main', 'commit_sha' => ROLLBACK_OLD_COMMIT, 'status' => DeploymentStatus::Succeeded]),
    };

    $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $target))
        ->assertStatus(409)
        ->assertJsonPath('code', 'DEPLOYMENT_NOT_ROLLBACK_TARGET')
        ->assertJsonPath('reason', $reason->value);

    expect(Deployment::query()->where('trigger', DeploymentTrigger::Rollback)->exists())->toBeFalse()
        ->and($project->fresh()->auto_deploy)->toBeTrue()
        ->and($project->fresh()->auto_deploy_paused_at)->toBeNull();
})->with([
    'not succeeded' => ['failed', RollbackIneligibleReason::NotSucceeded],
    'already active' => ['active', RollbackIneligibleReason::AlreadyActive],
    'no commit' => ['no commit', RollbackIneligibleReason::NoCommit],
    'branch changed' => ['other branch', RollbackIneligibleReason::BranchChanged],
    'settings unavailable' => ['no recorded settings', RollbackIneligibleReason::SettingsUnavailable],
]);

// ─── Idempotency and limits ──────────────────────────────────────────────────

test('the same idempotency key returns the same rollback, and another target with it conflicts', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();
    $older = rollbackSucceed(Deployment::factory()->for($project)->create([
        'sequence' => 0, 'branch' => 'main', 'commit_sha' => ROLLBACK_OLD_COMMIT, 'status' => DeploymentStatus::Queued,
    ]));
    $headers = ['Idempotency-Key' => 'rollback-key-1'];

    $first = $this->actingAs($team['owner'], 'web')->withHeaders($headers)->postJson(rollbackUrl($project, $old))->assertCreated();
    $again = $this->actingAs($team['owner'], 'web')->withHeaders($headers)->postJson(rollbackUrl($project, $old))->assertCreated();

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Deployment::query()->where('trigger', DeploymentTrigger::Rollback)->count())->toBe(1);

    $this->actingAs($team['owner'], 'web')->withHeaders($headers)->postJson(rollbackUrl($project, $older))->assertStatus(409);
    $this->actingAs($team['owner'], 'web')->withHeaders($headers)
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertStatus(409);

    expect(Deployment::query()->where('trigger', DeploymentTrigger::Rollback)->count())->toBe(1);
});

test('only a rollback that builds counts against the build rate', function (): void {
    // Both versions built in the last hour: the workspace is at its limit of 2.
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions(profile: ['max_builds_per_hour' => 2]);

    $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))
        ->assertCreated()
        ->assertJsonPath('data.build.mode', 'reused');

    $capabilities = array_values(array_diff(ROLLBACK_CAPABILITIES, ['image-reuse']));
    ['team' => $other, 'project' => $building, 'old' => $buildingOld] = rollbackTwoVersions($capabilities, profile: ['max_builds_per_hour' => 2]);

    $this->actingAs($other['owner'], 'web')->postJson(rollbackUrl($building, $buildingOld))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BUILD_RATE_LIMIT_EXCEEDED');
});

// ─── Auto-deploy pause ───────────────────────────────────────────────────────

test('a rollback pauses auto-deploy and the project says so', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();

    $id = $this->actingAs($team['member'], 'web')->postJson(rollbackUrl($project, $old))->assertCreated()->json('data.id');
    $rollback = Deployment::query()->findOrFail($id);

    $project->refresh();
    expect($project->auto_deploy)->toBeFalse()
        ->and($project->auto_deploy_paused_at?->toAtomString())->toBe(now()->toAtomString())
        ->and($project->auto_deploy_paused_by_deployment_id)->toBe($rollback->id);

    $this->actingAs($team['viewer'], 'web')->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.auto_deploy', false)
        ->assertJsonPath('data.auto_deploy_pause', [
            'paused_at' => now()->toAtomString(),
            'reason' => 'rollback',
            'deployment_id' => $rollback->id,
            'deployment_sequence' => $rollback->sequence,
            'held_push_count' => 0,
        ]);

    $audit = AuditEvent::query()->where('action', RecordRollbackAction::AUDIT_ACTION)->sole();
    expect($audit->actor_type)->toBe(User::class)
        ->and((string) $audit->actor_id)->toBe((string) $team['member']->id)
        ->and($audit->subject_id)->toBe($rollback->id)
        ->and($audit->metadata)->toBe([
            'project_id' => $project->id,
            'target_deployment_id' => $old->id,
            'target_sequence' => $old->sequence,
            'build_mode' => 'reused',
            'auto_deploy_paused' => true,
        ]);
});

test('a second rollback moves the pause and keeps when it started', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old, 'live' => $live] = rollbackTwoVersions();
    $first = Deployment::query()->findOrFail($this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))->json('data.id'));
    rollbackSucceed($first);
    $pausedAt = $project->fresh()->auto_deploy_paused_at;

    $this->travel(10)->minutes();
    $second = $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $live))->assertCreated()->json('data.id');

    $project->refresh();
    expect($project->auto_deploy)->toBeFalse()
        ->and($project->auto_deploy_paused_by_deployment_id)->toBe($second)
        ->and($project->auto_deploy_paused_at?->equalTo($pausedAt))->toBeTrue();
});

test('a rollback of a project whose auto-deploy was off pauses nothing', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions(projectAttributes: ['auto_deploy' => false]);

    $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))->assertCreated();

    expect($project->fresh()->auto_deploy_paused_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', RecordRollbackAction::AUDIT_ACTION)->sole()->metadata['auto_deploy_paused'])->toBeFalse();

    $this->actingAs($team['owner'], 'web')->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.auto_deploy_pause', null);
});

test('setting auto-deploy either way ends the pause; other fields keep it', function (bool $autoDeploy): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();
    $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))->assertCreated();
    // Auto-deploy can only be turned on for a project that receives pushes.
    $project->update(['github_installation_id' => GithubInstallation::factory()->create()->id]);

    $this->actingAs($team['member'], 'web')->patchJson("/api/v1/app/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.auto_deploy_pause.reason', 'rollback');

    $this->actingAs($team['viewer'], 'web')->patchJson("/api/v1/app/projects/{$project->id}", ['auto_deploy' => true])
        ->assertForbidden();
    expect($project->fresh()->auto_deploy_paused_at)->not->toBeNull();

    $this->actingAs($team['member'], 'web')->patchJson("/api/v1/app/projects/{$project->id}", ['auto_deploy' => $autoDeploy])
        ->assertOk()
        ->assertJsonPath('data.auto_deploy', $autoDeploy)
        ->assertJsonPath('data.auto_deploy_pause', null);

    $project->refresh();
    expect($project->auto_deploy_paused_at)->toBeNull()
        ->and($project->auto_deploy_paused_by_deployment_id)->toBeNull()
        // Resuming deploys nothing by itself.
        ->and($project->deployments()->count())->toBe(3);
})->with(['resume' => true, 'keep off' => false]);

// ─── Redeploys of a rollback ─────────────────────────────────────────────────

test('a password rotation redeploy of a live rollback keeps its recorded settings and image', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node, 'old' => $old] = rollbackTwoVersions();
    $database = databaseIn($team['workspace'], $node);
    attachDatabase($database, $project->fresh());

    $rollback = Deployment::query()->findOrFail($this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))->json('data.id'));
    rollbackSucceed($rollback);

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/rotate-password", ['redeploy_attached' => true])
        ->assertAccepted();
    $command = databaseCommand($database, AgentCommandType::RotateDatabasePassword);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    $redeploy = Deployment::query()->where('trigger', DeploymentTrigger::DatabasePasswordRotated)->sole();
    $payload = rollbackPayload($redeploy);

    expect($redeploy->settings_source_deployment_id)->toBe($old->id)
        ->and($redeploy->commit_sha)->toBe(ROLLBACK_OLD_COMMIT)
        ->and($redeploy->build_mode)->toBe(DeploymentBuildMode::Reused)
        ->and($redeploy->image_source_deployment_id)->toBe($old->id)
        ->and($payload['build_command'])->toBe('npm run build:v1')
        ->and($payload['start_command'])->toBe('node v1.js')
        ->and($payload['reuse_image_from_deployment_id'])->toBe($old->id);
});

test('a manual redeploy after a rollback runs the project settings again', function (): void {
    Http::fake(['api.github.com/repos/*/commits*' => Http::response([['sha' => ROLLBACK_NEW_COMMIT, 'commit' => ['message' => 'feat: head']]], 200)]);
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();
    rollbackSucceed(Deployment::query()->findOrFail($this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $old))->json('data.id')));

    $id = $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated()
        ->assertJsonPath('data.trigger', 'manual')
        ->assertJsonPath('data.rollback', null)
        ->json('data.id');

    $deployment = Deployment::query()->findOrFail($id);
    expect($deployment->settings_source_deployment_id)->toBeNull()
        ->and($deployment->commit_sha)->toBe(ROLLBACK_NEW_COMMIT)
        ->and(rollbackPayload($deployment)['build_command'])->toBe('npm run build:v2')
        // Still paused: only someone who sets auto-deploy ends it.
        ->and($project->fresh()->auto_deploy_paused_at)->not->toBeNull();
});

test('the fixture leaves two live versions with different settings and images', function (): void {
    ['project' => $project, 'old' => $old, 'live' => $live] = rollbackTwoVersions();

    expect($old->build_fingerprint)->not->toBe($live->build_fingerprint)
        ->and(EnvironmentVariable::query()->where('project_id', $project->id)->count())->toBe(2)
        ->and($project->runtime_status)->toBe(RuntimeStatus::Running)
        ->and(Project::query()->whereKey($project->id)->value('active_deployment_id'))->toBe($live->id);
});
