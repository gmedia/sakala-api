<?php

declare(strict_types=1);

use App\Actions\Bucket\CreateBucketAction;
use App\Actions\Bucket\UpdateBucketAction;
use App\Data\Bucket\CreateBucketData;
use App\Enums\WorkspaceRole;
use App\Exceptions\Bucket\BucketStorageLimitExceededException;
use App\Models\LimitProfile;
use App\Models\ManagedBucket;
use App\Models\User;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
});

test('two members cannot both take the last MB of a workspace: creates are serialized by the workspace row lock', function (): void {
    storageNode();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_buckets' => 5, 'max_bucket_storage_mb' => 100]);
    $team = teamWorkspace($first, [[$second, WorkspaceRole::Member]], ['limit_profile_id' => $profile->id]);
    $action = app(CreateBucketAction::class);

    whileTransactionHoldsLocks(
        holdLocks: fn () => $action->handle($first, $team, new CreateBucketData('Pertama', null, 60)),
        contend: fn () => $action->handle($second, $team, new CreateBucketData('Kedua', null, 60)),
    );

    expect(fn () => $action->handle($second, $team, new CreateBucketData('Kedua', null, 60)))
        ->toThrow(BucketStorageLimitExceededException::class);

    expect(ManagedBucket::reservedMbIn($team->id))->toBe(60);
});

test('a quota increase waits for a create in the same workspace, and then sees its reservation', function (): void {
    $node = storageNode();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_buckets' => 5, 'max_bucket_storage_mb' => 100]);
    $team = teamWorkspace($first, [[$second, WorkspaceRole::Member]], ['limit_profile_id' => $profile->id]);
    $bucket = ManagedBucket::factory()->inWorkspace($team)->onNode($node)->quota(20)->create();

    whileTransactionHoldsLocks(
        holdLocks: fn () => app(CreateBucketAction::class)->handle($first, $team, new CreateBucketData('Baru', null, 50)),
        contend: fn () => app(UpdateBucketAction::class)->handle($second, $bucket, null, 60),
    );

    expect(fn () => app(UpdateBucketAction::class)->handle($second, $bucket, null, 60))
        ->toThrow(BucketStorageLimitExceededException::class);

    expect(ManagedBucket::reservedMbIn($team->id))->toBe(70);
});
