<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bucketProfileFields(array $overrides = []): array
{
    return [
        'name' => 'Bucket',
        'max_projects' => 5,
        'max_active_containers' => 10,
        'max_active_deployments' => 2,
        'max_memory_mb' => 512,
        'max_cpu_millis' => 1000,
        'reason' => 'Uji bucket',
        ...$overrides,
    ];
}

test('admins set, change, and clear the bucket limits of a profile, with an audit trail', function (): void {
    $admin = adminApiAdmin();

    $id = $this->actingAs($admin, 'web')
        ->postJson('/api/v1/admin/limit-profiles', bucketProfileFields(['max_buckets' => 3, 'max_bucket_storage_mb' => 2048]))
        ->assertCreated()
        ->assertJsonPath('data.max_buckets', 3)
        ->assertJsonPath('data.max_bucket_storage_mb', 2048)
        ->json('data.id');

    $this->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_buckets' => null, 'max_bucket_storage_mb' => null, 'reason' => 'Ikut global'])
        ->assertOk()
        ->assertJsonPath('data.max_buckets', null)
        ->assertJsonPath('data.max_bucket_storage_mb', null);

    expect(AuditEvent::query()->where('action', 'limit_profile.created')->sole()->metadata['values'])
        ->toMatchArray(['max_buckets' => 3, 'max_bucket_storage_mb' => 2048]);
});

test('the bucket limits are bounded', function (array $fields, string $error): void {
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson('/api/v1/admin/limit-profiles', bucketProfileFields($fields))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with([
    'negative count' => [['max_buckets' => -1], 'max_buckets'],
    'count above the ceiling' => [['max_buckets' => LimitProfile::MAX_BUCKETS_CEILING + 1], 'max_buckets'],
    'storage above 1 TiB' => [['max_bucket_storage_mb' => LimitProfile::MAX_BUCKET_STORAGE_MB_CEILING + 1], 'max_bucket_storage_mb'],
]);

test('null follows the global setting, empty env values fall back to 0, and the code defaults are 0', function (): void {
    $service = app(PilotRuntimeLimitService::class);
    $profile = LimitProfile::factory()->create(['max_buckets' => null, 'max_bucket_storage_mb' => null]);

    expect(PilotRuntimeLimitService::DEFAULT_MAX_BUCKETS)->toBe(0)
        ->and(PilotRuntimeLimitService::DEFAULT_MAX_BUCKET_STORAGE_MB)->toBe(0);

    config(['sakala.buckets.default_max_count' => '', 'sakala.buckets.default_max_storage_mb' => null]);
    expect($service->maxBucketsFor($profile))->toBe(0)
        ->and($service->maxBucketStorageMbFor($profile))->toBe(0);

    config(['sakala.buckets.default_max_count' => '4', 'sakala.buckets.default_max_storage_mb' => '700']);
    expect($service->maxBucketsFor($profile))->toBe(4)
        ->and($service->maxBucketStorageMbFor(null))->toBe(700)
        ->and($service->maxBucketsFor(LimitProfile::factory()->create(['max_buckets' => 0])))->toBe(0);
});

test('the admin quota view shows the bucket limits and usage of a workspace', function (): void {
    $node = storageNode();
    $team = bucketTeam(['max_buckets' => 2, 'max_bucket_storage_mb' => 300]);
    bucketIn($team['workspace'], $node, ['quota_mb' => 100, 'reserved_quota_mb' => 120, 'size_bytes' => 512]);

    $this->actingAs(adminApiAdmin(), 'web')
        ->getJson("/api/v1/admin/workspaces/{$team['workspace']->id}")
        ->assertOk()
        ->assertJsonPath('data.quota.limits.max_buckets', 2)
        ->assertJsonPath('data.quota.limits.max_bucket_storage_mb', 300)
        ->assertJsonPath('data.quota.usage.buckets', 1)
        ->assertJsonPath('data.quota.usage.bucket_storage_mb', 120)
        ->assertJsonPath('data.quota.usage.bucket_usage_bytes', 512);
});
