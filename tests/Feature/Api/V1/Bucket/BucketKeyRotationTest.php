<?php

declare(strict_types=1);

use App\Actions\Attachment\RedeployAfterCredentialRotationAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Bucket\BucketCommandService;
use App\Support\Attachment\CredentialRotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const BUCKET_ROTATION_LIVE_COMMIT = 'b1c2d3e4f5a6978877869504a3b2c1d0e9f8a7b6';

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => 'ffffffffffffffffffffffffffffffffffffffff', 'commit' => ['message' => 'feat: newer commit']],
        ], 200),
    ]);
});

function keyRotationNode(): AgentNode
{
    return storageNode(overrides: [
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', AgentCommandType::MANAGED_S3_CAPABILITY],
    ]);
}

function keyRotationServedProject(Workspace $workspace, AgentNode $node, array $attributes = []): Project
{
    $project = Project::factory()->inWorkspace($workspace)->create([
        'branch' => 'main',
        'runtime_status' => RuntimeStatus::Running,
        ...$attributes,
    ]);
    $deployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'branch' => 'main',
        'commit_sha' => BUCKET_ROTATION_LIVE_COMMIT,
        'commit_message' => 'feat: live version',
        'agent_node_id' => $node->id,
        'config_fingerprint' => $project->deploymentConfigFingerprint(),
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);

    return $project->refresh();
}

function rotateBucket(ManagedBucket $bucket, array $body = []): TestResponse
{
    return test()->postJson(bucketsUrl((string) $bucket->workspace_id, "/{$bucket->id}/rotate-key"), $body);
}

function completeKeyRotation(ManagedBucket $bucket, AgentNode $node): AgentCommand
{
    $command = bucketCommand($bucket, AgentCommandType::RotateBucketKey);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    test()->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => ['bucket' => $bucket->s3_bucket, 'access_key_id' => $bucket->access_key_id]])
        ->assertNoContent();

    return $command->refresh();
}

/** @return Collection<int, Deployment> */
function keyRotationDeployments(): Collection
{
    return Deployment::query()->where('trigger', DeploymentTrigger::BucketKeyRotated)->get();
}

function showBucket(ManagedBucket $bucket): TestResponse
{
    return test()->getJson(bucketsUrl((string) $bucket->workspace_id, "/{$bucket->id}"));
}

test('the new secret is stored only once the node reports, the key ID stays, and attached projects need a redeploy', function (): void {
    $node = keyRotationNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $project = keyRotationServedProject($team['workspace'], $node);
    attachBucket($bucket, $project);
    $project->activeDeployment?->update(['config_fingerprint' => $project->fresh()?->deploymentConfigFingerprint()]);
    $old = (string) $bucket->secret_access_key;
    $fingerprint = $project->fresh()?->bucket_fingerprint;

    $this->actingAs($team['member'], 'web');

    rotateBucket($bucket)
        ->assertAccepted()
        ->assertJsonPath('data.key_rotation.status', 'pending')
        ->assertJsonPath('data.key_rotation.redeploy_attached', false)
        ->assertJsonPath('data.key_rotation.redeploys', null);

    $command = bucketCommand($bucket, AgentCommandType::RotateBucketKey);
    $new = app(BucketCommandService::class)->secretOf($command);

    expect($bucket->fresh()?->secret_access_key)->toBe($old)
        ->and($new)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($new)->not->toBe($old)
        ->and($command->payload['access_key_id'])->toBe($bucket->access_key_id)
        ->and($command->payload)->not->toHaveKey('redeploy_attached')
        ->and($project->fresh()?->requiresRedeploy())->toBeFalse();

    completeKeyRotation($bucket, $node);

    expect($bucket->fresh()?->secret_access_key)->toBe($new)
        ->and($bucket->fresh()?->access_key_id)->toBe($bucket->access_key_id)
        ->and($project->fresh()?->bucket_fingerprint)->not->toBe($fingerprint)
        ->and($project->fresh()?->requiresRedeploy())->toBeTrue()
        ->and(keyRotationDeployments())->toBeEmpty()
        ->and(AuditEvent::query()->where('action', 'bucket.key_rotated')->sole()->metadata['project_ids'])->toBe([$project->id]);

    showBucket($bucket)->assertJsonPath('data.key_rotation.status', 'completed');

    $this->postJson(bucketAttachUrlFor($bucket, $project))->assertOk()->assertJsonPath('data.redeploy_required', true);
});

function bucketAttachUrlFor(ManagedBucket $bucket, Project $project): string
{
    return bucketsUrl((string) $bucket->workspace_id, "/{$bucket->id}/attachments/{$project->id}");
}

test('with redeploy_attached the live commit of every attached project is redeployed once, others skipped with a reason', function (): void {
    $node = keyRotationNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $served = keyRotationServedProject($team['workspace'], $node, ['name' => 'A served']);
    $neverDeployed = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'B never deployed', 'branch' => 'main']);
    attachBucket($bucket, $served);
    attachBucket($bucket, $neverDeployed);

    $this->actingAs($team['member'], 'web');
    rotateBucket($bucket, ['redeploy_attached' => true])->assertAccepted()->assertJsonPath('data.key_rotation.redeploy_attached', true);

    expect(keyRotationDeployments())->toBeEmpty();

    $command = completeKeyRotation($bucket, $node);
    $deployment = keyRotationDeployments()->sole();
    $deployCommand = AgentCommand::query()->where('deployment_id', $deployment->id)->sole();

    expect($deployment->project_id)->toBe($served->id)
        ->and($deployment->requested_by)->toBe($team['member']->id)
        ->and($deployment->commit_sha)->toBe(BUCKET_ROTATION_LIVE_COMMIT)
        ->and($deployment->idempotency_key)->toBe("bucket-key-rotation:{$command->id}")
        ->and(Crypt::decryptString($deployCommand->payload['environment']['AWS_SECRET_ACCESS_KEY']))->toBe($bucket->fresh()?->secret_access_key);

    showBucket($bucket)
        ->assertJsonPath('data.key_rotation.status', 'completed')
        ->assertJsonPath('data.key_rotation.redeploys', [
            ['project_id' => $served->id, 'deployment_id' => $deployment->id, 'skipped_reason' => null],
            ['project_id' => $neverDeployed->id, 'deployment_id' => null, 'skipped_reason' => 'no_active_deployment'],
        ]);

    expect(AuditEvent::query()->where('action', 'bucket.key_rotation_redeploys_queued')->sole())
        ->actor_id->toBe((string) $team['member']->id);

    // Running again changes nothing.
    app(RedeployAfterCredentialRotationAction::class)->handle($command->id);

    expect(keyRotationDeployments())->toHaveCount(1)
        ->and(AuditEvent::query()->where('action', 'bucket.key_rotation_redeploys_queued')->count())->toBe(1);
});

test('a project whose database is not ready is skipped with database_not_ready on a bucket rotation', function (): void {
    $node = keyRotationNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $project = keyRotationServedProject($team['workspace'], $node);
    attachBucket($bucket, $project);
    attachDatabase(databaseIn($team['workspace'], databaseNode(), ['status' => DatabaseStatus::Provisioning]), $project);

    $this->actingAs($team['owner'], 'web');
    rotateBucket($bucket, ['redeploy_attached' => true])->assertAccepted();
    completeKeyRotation($bucket, $node);

    showBucket($bucket)->assertJsonPath('data.key_rotation.redeploys.0.skipped_reason', 'database_not_ready');
});

test('a failed rotation keeps the old secret, shows failed, and redeploys nothing', function (): void {
    $node = keyRotationNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $old = (string) $bucket->secret_access_key;

    $this->actingAs($team['owner'], 'web');
    rotateBucket($bucket, ['redeploy_attached' => true])->assertAccepted();
    $command = bucketCommand($bucket, AgentCommandType::RotateBucketKey);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'bucket_not_found', 'error_message' => 'no user'])
        ->assertNoContent();

    expect($bucket->fresh()?->secret_access_key)->toBe($old)
        ->and(keyRotationDeployments())->toBeEmpty()
        ->and(AuditEvent::query()->where('action', 'bucket.key_rotation_failed')->exists())->toBeTrue();

    showBucket($bucket)
        ->assertJsonPath('data.key_rotation.status', 'failed')
        ->assertJsonPath('data.last_failure.code', 'bucket_not_found');
});

test('one rotation at a time, only on a provisioned bucket, allowed while locked, and the body is a boolean', function (): void {
    $node = keyRotationNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Locked]);

    $this->actingAs($team['owner'], 'web');

    rotateBucket($bucket, ['redeploy_attached' => 'ya'])->assertUnprocessable()->assertJsonValidationErrors('redeploy_attached');
    rotateBucket($bucket)->assertAccepted();
    rotateBucket($bucket)->assertConflict()->assertJsonPath('code', 'BUCKET_BUSY');

    $provisioning = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Provisioning]);
    rotateBucket($provisioning)->assertConflict()->assertJsonPath('code', 'BUCKET_NOT_READY');

    // A reconcile creation on its way would reset the secret: wait for it.
    $reconciling = bucketIn($team['workspace'], $node);
    issuedBucketCommand($reconciling, AgentCommandType::CreateBucket, AgentCommandStatus::Pending);
    rotateBucket($reconciling)->assertConflict()->assertJsonPath('code', 'BUCKET_BUSY');

    $this->actingAs($team['viewer'], 'web');
    rotateBucket(bucketIn($team['workspace'], $node))->assertForbidden();

    expect(AuditEvent::query()->where('action', 'bucket.key_rotation_requested')->count())->toBe(1);
});

test('an unconfirmed rotation is sent again with the same secret, and nothing trusts either secret until it completes', function (string $how): void {
    $node = keyRotationNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $served = keyRotationServedProject($team['workspace'], $node);
    attachBucket($bucket, $served);
    $old = (string) $bucket->secret_access_key;

    $this->actingAs($team['owner'], 'web');
    rotateBucket($bucket, ['redeploy_attached' => true])->assertAccepted();
    $first = bucketCommand($bucket, AgentCommandType::RotateBucketKey);
    $new = app(BucketCommandService::class)->secretOf($first);
    $first->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    if ($how === 'outcome unknown') {
        $this->withHeaders(storageNodeHeaders($node))
            ->postJson("/api/agent/v1/commands/{$first->id}/fail", ['error_code' => 'bucket_outcome_unknown', 'error_message' => 'connection reset'])
            ->assertNoContent();
    } else {
        $first->update(['lease_expires_at' => now()->subMinute()]);
        $this->artisan('agent:expire-commands')->assertSuccessful();
    }

    $resent = bucketCommand($bucket, AgentCommandType::RotateBucketKey);

    expect($resent->id)->not->toBe($first->id)
        ->and($resent->status)->toBe(AgentCommandStatus::Pending)
        ->and($resent->agent_node_id)->toBe($node->id)
        ->and(app(BucketCommandService::class)->secretOf($resent))->toBe($new)
        ->and($resent->payload)->toBe($first->fresh()?->payload)
        ->and($resent->request_context)->toMatchArray(['redeploy_attached' => true, 'actor_id' => (string) $team['owner']->id, 'attempt' => 2])
        ->and($bucket->fresh()?->secret_access_key)->toBe($old)
        ->and(keyRotationDeployments())->toBeEmpty()
        ->and(AuditEvent::query()->where('action', 'bucket.key_rotation_resent')->sole()->metadata)->toMatchArray(['resent_command_id' => $resent->id, 'attempt' => 2])
        ->and(AuditEvent::query()->where('action', 'bucket.key_rotation_failed')->exists())->toBeFalse();

    showBucket($bucket)
        ->assertJsonPath('data.key_rotation.status', 'pending')
        ->assertJsonPath('data.last_failure', null);

    // A second rotation waits for this one.
    rotateBucket($bucket)->assertConflict()->assertJsonPath('code', 'BUCKET_BUSY');

    $done = completeKeyRotation($bucket, $node);
    $deployment = keyRotationDeployments()->sole();

    expect($done->id)->toBe($resent->id)
        ->and($bucket->fresh()?->secret_access_key)->toBe($new)
        ->and($deployment->idempotency_key)->toBe("bucket-key-rotation:{$resent->id}");

    showBucket($bucket)->assertJsonPath('data.key_rotation.status', 'completed');
})->with(['outcome unknown', 'lease expired']);

test('a definite rotation failure is not resent, and resending stops at the limit with an unknown outcome', function (): void {
    $node = keyRotationNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $old = (string) $bucket->secret_access_key;
    $service = app(BucketCommandService::class);

    $this->actingAs($team['owner'], 'web');
    $definite = $service->rotateKey($bucket, 'DefiniteSecret0123456789abcdefghijklmnop', ['attempt' => 1]);
    $definite->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$definite->id}/fail", ['error_code' => 'bucket_not_found', 'error_message' => 'no user'])
        ->assertNoContent();

    expect(AgentCommand::query()->where('bucket_id', $bucket->id)->where('type', AgentCommandType::RotateBucketKey)->count())->toBe(1);
    showBucket($bucket)->assertJsonPath('data.key_rotation.status', 'failed');

    $last = $service->rotateKey($bucket, 'LastSecret0123456789abcdefghijklmnopqrst', ['attempt' => CredentialRotation::MAX_UNCERTAIN_ATTEMPTS]);
    $last->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$last->id}/fail", ['error_code' => 'bucket_outcome_unknown', 'error_message' => 'still unsure'])
        ->assertNoContent();

    expect(AgentCommand::query()->where('bucket_id', $bucket->id)->where('type', AgentCommandType::RotateBucketKey)->count())->toBe(2)
        ->and($bucket->fresh()?->secret_access_key)->toBe($old)
        ->and(AuditEvent::query()->where('action', 'bucket.key_rotation_failed')->latest('id')->first()?->metadata)->toMatchArray(['outcome_unknown' => true]);

    showBucket($bucket)
        ->assertJsonPath('data.key_rotation.status', 'unknown')
        ->assertJsonPath('data.key_rotation.failed_at', null)
        ->assertJsonPath('data.last_failure.code', 'bucket_outcome_unknown');

    // A new rotation settles it.
    rotateBucket($bucket)->assertAccepted();
});
