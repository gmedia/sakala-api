<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Models\AgentCommand;
use App\Models\AgentCommandReport;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\ManagedBucket;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param  array<string, mixed>|null  $result */
function completeBucketCommand(AgentNode $node, AgentCommand $command, ?array $result = null): void
{
    test()->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => $result])
        ->assertNoContent();
}

function failBucketCommand(AgentNode $node, AgentCommand $command, string $code = 'bucket_operation_failed', string $message = 'rc refused'): void
{
    test()->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => $code, 'error_message' => $message])
        ->assertNoContent();
}

/** @return list<string> */
function bucketAuditActions(ManagedBucket $bucket): array
{
    return AuditEvent::query()->where('subject_id', $bucket->id)->orderBy('id')->pluck('action')->all();
}

/**
 * The result the agent reports on `complete` (contract section 3.4; the API
 * does not read it, but must accept it), for the fixture's bucket.
 */
function bucketResultFixture(AgentCommandType $type): array
{
    $bucket = 'b-8k2m4q7x9z1c3v5b6n0d';
    $key = 'SK7Q2M9X4K1C8V5B3N0D';

    return match ($type) {
        AgentCommandType::CreateBucket => ['bucket' => $bucket, 'access_key_id' => $key, 'quota_mb' => 256, 'created' => true],
        AgentCommandType::DeleteBucket => ['bucket' => $bucket, 'deleted' => true],
        AgentCommandType::RotateBucketKey => ['bucket' => $bucket, 'access_key_id' => $key],
        AgentCommandType::SetBucketQuota => ['bucket' => $bucket, 'quota_mb' => 512],
        AgentCommandType::LockBucket => ['bucket' => $bucket, 'access_key_id' => $key, 'locked' => true],
        AgentCommandType::UnlockBucket => ['bucket' => $bucket, 'access_key_id' => $key, 'locked' => false],
        default => throw new InvalidArgumentException('Not a bucket command.'),
    };
}

test('the storage node polls its bucket commands with the secret in plaintext for it only', function (): void {
    $node = storageNode();
    $other = storageNode();
    $runtime = AgentNode::factory()->create([
        'last_seen_at' => now(),
        'capabilities' => ['docker-runtime', 'dockerfile-build', AgentCommandType::MANAGED_POSTGRES_CAPABILITY],
        'token_hash' => hash_hmac('sha256', 'runtime-token', (string) config('app.key')),
    ]);
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['status' => BucketStatus::Provisioning]);
    $command = issuedBucketCommand($bucket, AgentCommandType::CreateBucket, AgentCommandStatus::Pending);

    $this->withHeaders(storageNodeHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $command->id)
        ->assertJsonPath('data.0.type', 'CreateBucket')
        ->assertJsonPath('data.0.project_id', null)
        ->assertJsonPath('data.0.deployment_id', null)
        ->assertJsonPath('data.0.payload', [
            'bucket' => $bucket->s3_bucket,
            'access_key_id' => $bucket->access_key_id,
            'secret_access_key' => $bucket->secret_access_key,
            'quota_mb' => $bucket->quota_mb,
        ]);

    $this->withHeaders(storageNodeHeaders($other))->getJson('/api/agent/v1/commands')->assertOk()->assertJsonCount(0, 'data');

    // A node without `managed-s3` never sees bucket commands, even pinned to it.
    $command->update(['agent_node_id' => $runtime->id]);
    $this->withHeaders(['Authorization' => 'Bearer runtime-token', 'X-Agent-Id' => $runtime->agent_id])
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the payloads match the agent protocol fixture key for key', function (AgentCommandType $type): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['status' => $type === AgentCommandType::UnlockBucket ? BucketStatus::Locked : BucketStatus::Ready]);
    $command = issuedBucketCommand($bucket, $type, AgentCommandStatus::Pending);
    $fixture = collect(agentCommandFixture('bucket-commands')['data'])->firstWhere('type', $type->value);

    $payload = $this->withHeaders(storageNodeHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->json('data.0.payload');

    expect(array_keys($payload))->toBe(array_keys($fixture['payload']))
        ->and(array_map(get_debug_type(...), $payload))->toBe(array_map(get_debug_type(...), $fixture['payload']));
})->with([
    AgentCommandType::CreateBucket,
    AgentCommandType::RotateBucketKey,
    AgentCommandType::SetBucketQuota,
    AgentCommandType::LockBucket,
    AgentCommandType::UnlockBucket,
    AgentCommandType::DeleteBucket,
]);

test('another node cannot claim or report on a bucket command, and gets no payload', function (): void {
    $node = storageNode();
    $other = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['status' => BucketStatus::Provisioning]);
    $command = issuedBucketCommand($bucket, AgentCommandType::CreateBucket, AgentCommandStatus::Pending);

    $claim = $this->withHeaders(storageNodeHeaders($other))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim", [])
        ->assertConflict();

    expect($claim->getContent())->not->toContain((string) $bucket->secret_access_key);

    $this->withHeaders(storageNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();

    $this->withHeaders(storageNodeHeaders($other))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertConflict();

    expect(bucketStatus($bucket))->toBe(BucketStatus::Provisioning);
});

test('a created bucket becomes ready; a repeated creation on a ready or locked one only records a reconcile', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Provisioning]);

    completeBucketCommand($node, issuedBucketCommand($bucket, AgentCommandType::CreateBucket), bucketResultFixture(AgentCommandType::CreateBucket));

    expect(bucketStatus($bucket))->toBe(BucketStatus::Ready)
        ->and(bucketAuditActions($bucket))->toBe(['bucket.provisioned']);

    $locked = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Locked]);
    completeBucketCommand($node, issuedBucketCommand($locked, AgentCommandType::CreateBucket), ['created' => false]);

    expect(bucketStatus($locked))->toBe(BucketStatus::Locked)
        ->and(bucketAuditActions($locked))->toBe(['bucket.reconciled']);
});

test('a failed or expired creation marks the bucket failed with the code', function (string $how): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['status' => BucketStatus::Provisioning]);
    $command = issuedBucketCommand($bucket, AgentCommandType::CreateBucket);

    if ($how === 'fail') {
        failBucketCommand($node, $command, 'bucket_capacity_exceeded', 'total quota');
    } else {
        $command->update(['lease_expires_at' => now()->subMinute()]);
        $this->artisan('agent:expire-commands')->assertSuccessful();
    }

    $audit = AuditEvent::query()->where('action', 'bucket.provisioning_failed')->sole();

    expect(bucketStatus($bucket))->toBe(BucketStatus::Failed)
        ->and($audit->metadata['error_code'])->toBe($how === 'fail' ? 'bucket_capacity_exceeded' : 'command_lease_expired')
        ->and($audit->actor_id)->toBe($how === 'fail' ? $node->id : 'agent:expire-commands');

    $this->actingAs(bucketTeam()['admin'], 'web')
        ->getJson(bucketsUrl((string) $bucket->workspace_id, "/{$bucket->id}"))
        ->assertJsonPath('data.last_failure.command', 'CreateBucket')
        ->assertJsonPath('data.last_failure.user_fixable', false);
})->with(['fail', 'expire']);

test('a deleted bucket is soft deleted, its secret erased everywhere, and a late result changes nothing', function (): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['status' => BucketStatus::Deleting]);
    $create = issuedBucketCommand($bucket, AgentCommandType::CreateBucket, AgentCommandStatus::Succeeded);
    $rotate = issuedBucketCommand($bucket, AgentCommandType::RotateBucketKey, AgentCommandStatus::Failed);
    $lock = issuedBucketCommand($bucket, AgentCommandType::LockBucket);
    $delete = issuedBucketCommand($bucket, AgentCommandType::DeleteBucket);

    // A lock that lands while the bucket is being deleted does not lock it.
    completeBucketCommand($node, $lock);
    expect(bucketStatus($bucket))->toBe(BucketStatus::Deleting);

    completeBucketCommand($node, $delete, bucketResultFixture(AgentCommandType::DeleteBucket));

    $gone = ManagedBucket::withTrashed()->findOrFail($bucket->id);

    expect($gone->trashed())->toBeTrue()
        ->and($gone->status)->toBe(BucketStatus::Deleted)
        ->and($gone->secret_access_key)->toBeNull()
        ->and($create->fresh()?->payload)->not->toHaveKey(AgentCommandPayloadMaterializer::SECRET_KEY)
        ->and($rotate->fresh()?->payload)->not->toHaveKey(AgentCommandPayloadMaterializer::SECRET_KEY)
        ->and(bucketAuditActions($bucket))->toBe(['bucket.deleted']);
});

test('a failed delete keeps the bucket deleting and is audited', function (): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['status' => BucketStatus::Deleting]);

    failBucketCommand($node, issuedBucketCommand($bucket, AgentCommandType::DeleteBucket), 'bucket_admin_unavailable');

    expect(bucketStatus($bucket))->toBe(BucketStatus::Deleting)
        ->and(bucketAuditActions($bucket))->toBe(['bucket.delete_failed']);
});

test('lock and unlock move the status only from the expected one; failures are audited', function (): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node);

    completeBucketCommand($node, issuedBucketCommand($bucket, AgentCommandType::LockBucket), bucketResultFixture(AgentCommandType::LockBucket));
    expect(bucketStatus($bucket))->toBe(BucketStatus::Locked);

    completeBucketCommand($node, issuedBucketCommand($bucket, AgentCommandType::UnlockBucket), bucketResultFixture(AgentCommandType::UnlockBucket));
    expect(bucketStatus($bucket))->toBe(BucketStatus::Ready);

    // An unlock that lands on a ready bucket changes nothing.
    completeBucketCommand($node, issuedBucketCommand($bucket, AgentCommandType::UnlockBucket));

    failBucketCommand($node, issuedBucketCommand($bucket, AgentCommandType::LockBucket), 'bucket_not_found');
    failBucketCommand($node, issuedBucketCommand($bucket, AgentCommandType::UnlockBucket), 'bucket_not_found');

    expect(bucketStatus($bucket))->toBe(BucketStatus::Ready)
        ->and(bucketAuditActions($bucket))->toBe(['bucket.locked', 'bucket.unlocked', 'bucket.lock_failed', 'bucket.unlock_failed']);
});

test('the secrets are redacted from failure messages and command reports', function (): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node);
    $current = (string) $bucket->secret_access_key;
    $next = 'NextSecret0123456789abcdefghijklmnopqrstu';
    $rotate = issuedBucketCommand($bucket, AgentCommandType::RotateBucketKey, secret: $next);

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$rotate->id}/logs", ['logs' => [[
            'stream' => 'stderr',
            'message' => "passwd {$next} replacing {$current}",
            'recorded_at' => now()->toIso8601String(),
        ]]])
        ->assertOk();

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$rotate->id}/events", ['events' => [[
            'level' => 'error',
            'type' => 'bucket.rotate.failed',
            'message' => "AWS_SECRET_ACCESS_KEY={$next}",
            'metadata' => ['detail' => $current],
            'occurred_at' => now()->toIso8601String(),
        ]]])
        ->assertOk();

    failBucketCommand($node, $rotate, 'bucket_operation_failed', "rc refused {$next}");

    $reports = AgentCommandReport::query()->where('agent_command_id', $rotate->id)->get();

    expect($rotate->fresh()?->error_message)->toBe('rc refused [REDACTED]')
        ->and($reports->pluck('message')->all())->toBe(['passwd [REDACTED] replacing [REDACTED]', 'AWS_SECRET_ACCESS_KEY=[REDACTED]'])
        ->and($reports->last()?->metadata)->toBe(['detail' => '[REDACTED]'])
        ->and(json_encode(AuditEvent::query()->get()->toArray()))->not->toContain($next);
});

test('bucket commands are never reassigned to another node', function (): void {
    $node = storageNode(overrides: ['last_seen_at' => now()->subHour()]);
    storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['status' => BucketStatus::Provisioning]);
    $command = issuedBucketCommand($bucket, AgentCommandType::CreateBucket, AgentCommandStatus::Pending);

    $this->artisan('agent:assign-commands')->assertSuccessful();

    expect($command->fresh()?->agent_node_id)->toBe($node->id);
});
