<?php

declare(strict_types=1);

use App\Enums\BucketStatus;
use App\Models\AgentNode;
use App\Models\ManagedBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the protocol fixture is accepted: the object storage is kept, the usage list is not', function (): void {
    $node = storageNode(endpoint: false);
    $fixture = agentHeartbeatFixture('object-storage-host');
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['s3_bucket' => $fixture['metadata']['buckets'][0]['name']]);
    $empty = bucketIn($team['workspace'], $node, ['s3_bucket' => $fixture['metadata']['buckets'][1]['name'], 'size_bytes' => 99]);

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', storageHeartbeat(buckets: $fixture['metadata']['buckets']))
        ->assertOk();

    $node->refresh();
    $bucket->refresh();

    expect($node->objectStorage()?->endpoint)->toBe('https://s3.sakala.app')
        ->and($node->objectStorage()?->region)->toBe('us-east-1')
        ->and($node->objectStorage()?->capacityMb)->toBe(5120)
        ->and($node->objectStorageAvailable())->toBeTrue()
        ->and($node->hasCapability('managed-s3'))->toBeTrue()
        ->and($node->metadata)->not->toHaveKey('buckets')
        ->and($node->metadata['detail_counts']['buckets'])->toBe(2)
        ->and($bucket->size_bytes)->toBe(1048576)
        ->and($empty->fresh()?->size_bytes)->toBe(0)
        ->and($bucket->usage_checked_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

test('usage only changes buckets of the reporting node, by their name on it, deleting ones included, in one write', function (): void {
    $node = storageNode();
    $other = storageNode();
    $team = bucketTeam();
    $mine = bucketIn($team['workspace'], $node, ['size_bytes' => 1]);
    $deleting = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Deleting, 'size_bytes' => 5]);
    $unlisted = bucketIn($team['workspace'], $node, ['size_bytes' => 7]);
    $foreign = bucketIn($team['workspace'], $other, ['size_bytes' => 3]);

    DB::enableQueryLog();

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', storageHeartbeat(buckets: [
            ['name' => $mine->s3_bucket, 'size_bytes' => 100],
            ['name' => $deleting->s3_bucket, 'size_bytes' => 200],
            ['name' => $foreign->s3_bucket, 'size_bytes' => 999],
            ['name' => 'b-unknownbucket0000000', 'size_bytes' => 999],
        ]))
        ->assertOk();

    $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'update "buckets"'));

    expect($mine->fresh()?->size_bytes)->toBe(100)
        ->and($deleting->fresh()?->size_bytes)->toBe(200)
        ->and($unlisted->fresh()?->size_bytes)->toBe(7)
        ->and($unlisted->fresh()?->usage_checked_at)->toBeNull()
        ->and($foreign->fresh()?->size_bytes)->toBe(3)
        ->and($writes)->toHaveCount(1);
});

test('a heartbeat without a usage list changes no bucket, and an empty one changes nothing either', function (): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['size_bytes' => 42]);

    $this->withHeaders(storageNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', storageHeartbeat())->assertOk();
    $this->withHeaders(storageNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', storageHeartbeat(buckets: []))->assertOk();
    $this->withHeaders(storageNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', heartbeatPayload())->assertOk();

    expect($bucket->fresh()?->size_bytes)->toBe(42)
        ->and($bucket->fresh()?->usage_checked_at)->toBeNull();
});

test('a malformed object storage report rejects the whole heartbeat', function (array $objectStorage): void {
    $node = storageNode();

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', storageHeartbeat(objectStorage: $objectStorage))
        ->assertUnprocessable();

    expect($node->fresh()?->objectStorage()?->endpoint)->toBe(STORAGE_NODE_ENDPOINT);
})->with([
    'plain http to a public host' => [['endpoint' => 'http://s3.sakala.app', 'region' => 'us-east-1', 'capacity_mb' => 10]],
    'a path' => [['endpoint' => 'https://s3.sakala.app/tenant', 'region' => 'us-east-1', 'capacity_mb' => 10]],
    'a query' => [['endpoint' => 'https://s3.sakala.app?x=1', 'region' => 'us-east-1', 'capacity_mb' => 10]],
    'user info' => [['endpoint' => 'https://root:secret@s3.sakala.app', 'region' => 'us-east-1', 'capacity_mb' => 10]],
    'not a URL' => [['endpoint' => 's3.sakala.app', 'region' => 'us-east-1', 'capacity_mb' => 10]],
    'bad region' => [['endpoint' => 'https://s3.sakala.app', 'region' => 'US East 1', 'capacity_mb' => 10]],
    'no region' => [['endpoint' => 'https://s3.sakala.app', 'capacity_mb' => 10]],
    'zero capacity' => [['endpoint' => 'https://s3.sakala.app', 'region' => 'us-east-1', 'capacity_mb' => 0]],
    'no capacity' => [['endpoint' => 'https://s3.sakala.app', 'region' => 'us-east-1']],
    'available not a boolean' => [['endpoint' => 'https://s3.sakala.app', 'region' => 'us-east-1', 'capacity_mb' => 10, 'available' => 'yes']],
]);

test('plain http is accepted only for a loopback endpoint, for local development', function (string $endpoint): void {
    $node = storageNode();

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', storageHeartbeat(objectStorage: ['endpoint' => $endpoint, 'region' => 'us-east-1', 'capacity_mb' => 10]))
        ->assertOk();

    expect($node->fresh()?->objectStorage()?->endpoint)->toBe($endpoint);
})->with(['http://localhost:9000', 'http://127.0.0.1:8190', 'https://s3.sakala.app:8443']);

test('a malformed usage list rejects the whole heartbeat', function (array $buckets): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['size_bytes' => 42]);

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', storageHeartbeat(buckets: array_map(
            static fn (array $item): array => ['name' => $bucket->s3_bucket, ...$item],
            $buckets,
        )))
        ->assertUnprocessable();

    expect($bucket->fresh()?->size_bytes)->toBe(42);
})->with([
    'negative size' => [[['size_bytes' => -1]]],
    'above 1 PiB' => [[['size_bytes' => 1_125_899_906_842_625]]],
    'size missing' => [[['size_bytes' => null]]],
    'name outside the managed pattern' => [[['name' => 'w-operator-bucket', 'size_bytes' => 1]]],
    'name too short' => [[['name' => 'b-short', 'size_bytes' => 1]]],
]);

test('more than 1000 items are refused, and detail_counts.buckets must be a count', function (): void {
    $node = storageNode();
    $items = array_map(static fn (int $i): array => ['name' => sprintf('b-%020d', $i), 'size_bytes' => 1], range(1, 1001));

    $this->withHeaders(storageNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', storageHeartbeat(buckets: $items))->assertUnprocessable();

    $payload = storageHeartbeat(buckets: []);
    $payload['metadata']['detail_counts']['buckets'] = -1;
    $this->withHeaders(storageNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertUnprocessable();
});

test('a node that reports its store unavailable stays eligible but is sorted last for new buckets', function (): void {
    $down = storageNode(capacityMb: 10000, available: false);
    $up = storageNode(capacityMb: 100);
    $team = bucketTeam();

    $id = $this->actingAs($team['owner'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['name' => 'Satu', 'quota_mb' => 10])
        ->assertCreated()
        ->json('data.id');

    expect(ManagedBucket::query()->findOrFail($id)->node_id)->toBe($up->id)
        ->and(AgentNode::query()->findOrFail($down->id)->objectStorageAvailable())->toBeFalse();
});
