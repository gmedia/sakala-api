<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Models\AuditEvent;
use App\Models\ManagedBucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function createBucketAs(User $user, string $workspaceId, array $body): TestResponse
{
    return test()->actingAs($user, 'web')->postJson("/api/v1/app/workspaces/{$workspaceId}/buckets", $body);
}

function patchBucketAs(User $user, ManagedBucket $bucket, array $body): TestResponse
{
    return test()->actingAs($user, 'web')->patchJson("/api/v1/app/workspaces/{$bucket->workspace_id}/buckets/{$bucket->id}", $body);
}

test('the bucket count limit refuses one more, and 0 refuses the first', function (): void {
    $node = storageNode();
    $team = bucketTeam(['max_buckets' => 1]);
    bucketIn($team['workspace'], $node);
    // A failed creation does not keep the slot.
    bucketIn($team['workspace'], $node, ['status' => BucketStatus::Failed]);

    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Dua', 'quota_mb' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUCKET_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 1)
        ->assertJsonPath('current', 1);

    $closed = bucketTeam(['max_buckets' => 0]);

    createBucketAs($closed['owner'], $closed['workspace']->id, ['name' => 'Satu', 'quota_mb' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUCKET_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 0)
        ->assertJsonPath('message', 'Workspace ini belum bisa membuat bucket. Minta admin mengaktifkannya lewat profil batas workspace.');
});

test('the code defaults allow no bucket until a profile opens the workspace', function (): void {
    storageNode();
    $owner = User::factory()->create();
    $workspace = teamWorkspace($owner);

    createBucketAs($owner, $workspace->id, ['name' => 'Satu', 'quota_mb' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUCKET_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 0);
});

test('the storage limit counts reservations, not usage, and refuses what would pass it', function (): void {
    $node = storageNode();
    $team = bucketTeam(['max_bucket_storage_mb' => 100]);
    // Reserved counts, used does not; a failed bucket reserves nothing.
    bucketIn($team['workspace'], $node, ['quota_mb' => 40, 'reserved_quota_mb' => 60, 'size_bytes' => 1]);
    bucketIn($team['workspace'], $node, ['status' => BucketStatus::Failed, 'quota_mb' => 500, 'reserved_quota_mb' => 500]);

    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Besar', 'quota_mb' => 41])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUCKET_STORAGE_LIMIT_EXCEEDED')
        ->assertJsonPath('limit_mb', 100)
        ->assertJsonPath('current_mb', 60)
        ->assertJsonPath('requested_mb', 41);

    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Pas', 'quota_mb' => 40])->assertCreated();

    $none = bucketTeam(['max_bucket_storage_mb' => 0]);

    createBucketAs($none['owner'], $none['workspace']->id, ['name' => 'Nol'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUCKET_STORAGE_LIMIT_EXCEEDED')
        ->assertJsonPath('limit_mb', 0);
});

test('without quota_mb a bucket gets the default capped at what the workspace has left', function (): void {
    $node = storageNode();
    config(['sakala.buckets.default_quota_mb' => 256]);
    $team = bucketTeam(['max_bucket_storage_mb' => 300]);

    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Pertama'])->assertCreated()->assertJsonPath('data.quota_mb', 256);
    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Kedua'])->assertCreated()->assertJsonPath('data.quota_mb', 44);
    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Ketiga'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUCKET_STORAGE_LIMIT_EXCEEDED')
        ->assertJsonPath('requested_mb', ManagedBucket::MIN_QUOTA_MB);

    expect(ManagedBucket::reservedMbIn($team['workspace']->id))->toBe(300);
});

test('admins are exempt from the workspace limits but not from node capacity', function (): void {
    storageNode(capacityMb: 100);
    $team = bucketTeam(['max_buckets' => 0, 'max_bucket_storage_mb' => 0]);
    config(['sakala.buckets.default_quota_mb' => 64]);

    createBucketAs($team['admin'], $team['workspace']->id, ['name' => 'Admin'])->assertCreated()->assertJsonPath('data.quota_mb', 64);

    createBucketAs($team['admin'], $team['workspace']->id, ['name' => 'Kebesaran', 'quota_mb' => 37])
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_CAPACITY_UNAVAILABLE');

    expect(ManagedBucket::query()->count())->toBe(1);
});

test('placement picks the node with the most free allocation, never one without room, and failed buckets reserve nothing', function (): void {
    $small = storageNode(capacityMb: 90);
    $large = storageNode(capacityMb: 1000);
    $team = bucketTeam();
    // The large node is mostly reserved already, by any workspace; a failed bucket reserves nothing.
    bucketIn(bucketTeam()['workspace'], $large, ['quota_mb' => 900, 'reserved_quota_mb' => 900]);
    bucketIn($team['workspace'], $large, ['status' => BucketStatus::Failed, 'quota_mb' => 50, 'reserved_quota_mb' => 50]);

    expect(ManagedBucket::reservedMbOnNode($large->id))->toBe(900);

    $id = createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Satu', 'quota_mb' => 40])->assertCreated()->json('data.id');
    expect(ManagedBucket::query()->findOrFail($id)->node_id)->toBe($large->id);

    // 90 left on the small node, 60 on the large one.
    $id = createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Dua', 'quota_mb' => 61])->assertCreated()->json('data.id');
    expect(ManagedBucket::query()->findOrFail($id)->node_id)->toBe($small->id);

    // 29 left on the small node, 60 on the large one.
    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Tiga', 'quota_mb' => 61])
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_CAPACITY_UNAVAILABLE')
        ->assertJsonMissingPath('capacity_mb');
});

test('without a managed-s3 node the region is unavailable, named when one was asked for', function (): void {
    databaseNode();
    $team = bucketTeam();

    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Satu', 'quota_mb' => 1])
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_REGION_UNAVAILABLE')
        ->assertJsonPath('region', null);

    storageNode('jkt');

    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Dua', 'quota_mb' => 1, 'region' => 'sby'])
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_REGION_UNAVAILABLE')
        ->assertJsonPath('region', 'sby');

    // A revoked node never hosts one.
    storageNode('sby', ['auth_status' => AgentAuthStatus::Revoked]);
    createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Tiga', 'quota_mb' => 1, 'region' => 'sby'])
        ->assertJsonPath('code', 'BUCKET_REGION_UNAVAILABLE');
});

test('an offline node with room is chosen when no online one has room, and the bucket waits for it', function (): void {
    storageNode(capacityMb: 10);
    $offline = storageNode(capacityMb: 1000, overrides: ['last_seen_at' => now()->subHour()]);
    $team = bucketTeam();

    $id = createBucketAs($team['owner'], $team['workspace']->id, ['name' => 'Tunggu', 'quota_mb' => 500])->assertCreated()->json('data.id');

    expect(ManagedBucket::query()->findOrFail($id)->node_id)->toBe($offline->id);
});

test('raising the quota reserves the new value at once and answers 202 with the pending quota', function (): void {
    $node = storageNode();
    $team = bucketTeam(['max_bucket_storage_mb' => 100]);
    $bucket = bucketIn($team['workspace'], $node, ['quota_mb' => 20, 'reserved_quota_mb' => 20]);

    patchBucketAs($team['member'], $bucket, ['quota_mb' => 80])
        ->assertAccepted()
        ->assertJsonPath('data.quota_mb', 20)
        ->assertJsonPath('data.pending_quota_mb', 80);

    $command = bucketCommand($bucket, AgentCommandType::SetBucketQuota);

    expect($bucket->fresh()?->reserved_quota_mb)->toBe(80)
        ->and($command->payload)->toBe(['bucket' => $bucket->s3_bucket, 'quota_mb' => 80])
        ->and(AuditEvent::query()->where('action', 'bucket.quota_change_requested')->sole()->metadata)->toMatchArray(['from_mb' => 20, 'to_mb' => 80]);

    // Another change waits for this one.
    patchBucketAs($team['member'], $bucket, ['quota_mb' => 30])
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_BUSY');

    // A rename still goes through, with 200.
    patchBucketAs($team['member'], $bucket, ['name' => 'Ganti'])->assertOk();
});

test('an increase is checked against the workspace without the bucket itself, and against its node', function (): void {
    $node = storageNode(capacityMb: 200);
    $team = bucketTeam(['max_bucket_storage_mb' => 100]);
    $bucket = bucketIn($team['workspace'], $node, ['quota_mb' => 50, 'reserved_quota_mb' => 50]);
    bucketIn($team['workspace'], $node, ['quota_mb' => 30, 'reserved_quota_mb' => 30]);

    patchBucketAs($team['owner'], $bucket, ['quota_mb' => 71])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUCKET_STORAGE_LIMIT_EXCEEDED')
        ->assertJsonPath('current_mb', 30)
        ->assertJsonPath('requested_mb', 71);

    // Room in the workspace for an admin, but not on the node (200 - 30 others).
    patchBucketAs($team['admin'], $bucket, ['quota_mb' => 171])
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_CAPACITY_UNAVAILABLE');

    patchBucketAs($team['owner'], $bucket, ['quota_mb' => 70])->assertAccepted();

    expect($bucket->fresh()?->reserved_quota_mb)->toBe(70);
});

test('a decrease keeps the old reservation until the node reports, then releases it; 0 storage still allows it', function (): void {
    $node = storageNode();
    $team = bucketTeam(['max_bucket_storage_mb' => 0]);
    $bucket = bucketIn($team['workspace'], $node, ['quota_mb' => 50, 'reserved_quota_mb' => 50, 'size_bytes' => 40 * 1024 * 1024]);

    // Below current usage is allowed.
    patchBucketAs($team['owner'], $bucket, ['quota_mb' => 10])->assertAccepted();
    expect($bucket->fresh()?->reserved_quota_mb)->toBe(50);

    $command = bucketCommand($bucket, AgentCommandType::SetBucketQuota);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => ['bucket' => $bucket->s3_bucket, 'quota_mb' => 10]])
        ->assertNoContent();

    expect($bucket->fresh()?->quota_mb)->toBe(10)
        ->and($bucket->fresh()?->reserved_quota_mb)->toBe(10)
        ->and(AuditEvent::query()->where('action', 'bucket.quota_changed')->sole()->metadata)->toMatchArray(['from_mb' => 50, 'to_mb' => 10]);

    // 0 storage refuses any increase.
    patchBucketAs($team['owner'], $bucket, ['quota_mb' => 11])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'BUCKET_STORAGE_LIMIT_EXCEEDED');
});

test('a failed quota change restores the reservation to the applied quota', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['quota_mb' => 20, 'reserved_quota_mb' => 20]);

    patchBucketAs($team['owner'], $bucket, ['quota_mb' => 90])->assertAccepted();
    $command = bucketCommand($bucket, AgentCommandType::SetBucketQuota);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'bucket_capacity_exceeded', 'error_message' => 'disk floor'])
        ->assertNoContent();

    expect($bucket->fresh()?->quota_mb)->toBe(20)
        ->and($bucket->fresh()?->reserved_quota_mb)->toBe(20)
        ->and(AuditEvent::query()->where('action', 'bucket.quota_change_failed')->sole()->metadata)->toMatchArray(['quota_mb' => 90, 'error_code' => 'bucket_capacity_exceeded']);

    patchBucketAs($team['owner'], $bucket, [])->assertUnprocessable();
    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/workspaces/{$team['workspace']->id}/buckets/{$bucket->id}")
        ->assertJsonPath('data.pending_quota_mb', null)
        ->assertJsonPath('data.last_failure.code', 'bucket_capacity_exceeded')
        ->assertJsonPath('data.last_failure.user_fixable', false);
});

test('a quota change needs a ready or locked bucket; an unchanged quota answers 200', function (BucketStatus $status, int $expected): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['status' => $status, 'quota_mb' => 20, 'reserved_quota_mb' => 20]);

    $response = patchBucketAs($team['owner'], $bucket, ['quota_mb' => 30])->assertStatus($expected);

    if ($expected === 409) {
        $response->assertJsonPath('code', 'BUCKET_NOT_READY')->assertJsonPath('status', $status->value);
    }

    patchBucketAs($team['owner'], $bucket->fresh() ?? $bucket, ['quota_mb' => ($bucket->fresh() ?? $bucket)->quota_mb])->assertOk();
})->with([
    'provisioning' => [BucketStatus::Provisioning, 409],
    'failed' => [BucketStatus::Failed, 409],
    'deleting' => [BucketStatus::Deleting, 409],
    'locked' => [BucketStatus::Locked, 202],
]);

test('pilot-limits shows the bucket quotas, usage, availability, and default quota', function (): void {
    $node = storageNode();
    config(['sakala.buckets.default_quota_mb' => 128]);
    $team = bucketTeam(['max_buckets' => 4, 'max_bucket_storage_mb' => 500]);
    bucketIn($team['workspace'], $node, ['quota_mb' => 100, 'reserved_quota_mb' => 150, 'size_bytes' => 2048]);
    bucketIn($team['workspace'], $node, ['quota_mb' => 50, 'reserved_quota_mb' => 50, 'size_bytes' => null]);
    bucketIn($team['workspace'], $node, ['status' => BucketStatus::Failed, 'quota_mb' => 70, 'reserved_quota_mb' => 70]);

    $this->actingAs($team['viewer'], 'web')
        ->getJson('/api/v1/app/pilot-limits?workspace_id='.$team['workspace']->id)
        ->assertOk()
        ->assertJsonPath('data.quotas.max_buckets', 4)
        ->assertJsonPath('data.quotas.current_buckets_count', 2)
        ->assertJsonPath('data.quotas.max_bucket_storage_mb', 500)
        ->assertJsonPath('data.quotas.current_bucket_storage_mb', 200)
        ->assertJsonPath('data.quotas.current_bucket_usage_bytes', 2048)
        ->assertJsonPath('data.buckets.available', true)
        ->assertJsonPath('data.buckets.default_quota_mb', 128);
});

test('buckets are unavailable without a node, with one that reports its store down, or without its report', function (): void {
    $team = bucketTeam();
    $url = '/api/v1/app/pilot-limits?workspace_id='.$team['workspace']->id;

    $this->actingAs($team['owner'], 'web')->getJson($url)->assertJsonPath('data.buckets.available', false);

    storageNode(available: false);
    storageNode(endpoint: false);
    $this->actingAs($team['owner'], 'web')->getJson($url)->assertJsonPath('data.buckets.available', false);

    storageNode();
    $this->actingAs($team['owner'], 'web')->getJson($url)->assertJsonPath('data.buckets.available', true);
});
