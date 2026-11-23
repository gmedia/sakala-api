<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

const BUCKET_MB = 1024 * 1024;

test('check-usage warns owners and members once at 80 %, and clears the mark below it', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['name' => 'Foto', 'quota_mb' => 10, 'reserved_quota_mb' => 10, 'size_bytes' => 8 * BUCKET_MB]);
    $below = bucketIn($team['workspace'], $node, ['quota_mb' => 10, 'reserved_quota_mb' => 10, 'size_bytes' => 7 * BUCKET_MB]);

    $this->artisan('buckets:check-usage')->assertSuccessful();
    $this->artisan('buckets:check-usage')->assertSuccessful();

    $audit = AuditEvent::query()->where('action', 'bucket.storage_warning')->sole();

    expect($audit->subject_id)->toBe($bucket->id)
        ->and($audit->actor_id)->toBe('buckets:check-usage')
        ->and($bucket->fresh()?->storage_warned_at)->not->toBeNull()
        ->and($below->fresh()?->storage_warned_at)->toBeNull()
        ->and($team['owner']->notifications()->sole()->data)->toMatchArray(['title' => 'Bucket hampir penuh', 'category' => 'workspace'])
        ->and($team['owner']->notifications()->sole()->data['message'])->toContain('upload baru ditolak')
        ->and($team['member']->notifications()->count())->toBe(1)
        ->and($team['viewer']->notifications()->count())->toBe(0)
        // There is no lock action: the node's hard quota refuses uploads.
        ->and(AgentCommand::query()->where('bucket_id', $bucket->id)->exists())->toBeFalse()
        ->and(bucketStatus($bucket))->toBe(BucketStatus::Ready);

    $bucket->update(['size_bytes' => 1 * BUCKET_MB]);
    $this->artisan('buckets:check-usage')->assertSuccessful();

    expect($bucket->fresh()?->storage_warned_at)->toBeNull();

    $bucket->update(['size_bytes' => 9 * BUCKET_MB]);
    $this->artisan('buckets:check-usage')->assertSuccessful();

    expect(AuditEvent::query()->where('action', 'bucket.storage_warning')->count())->toBe(2);
});

test('check-usage skips buckets without a reading or outside ready and locked', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    bucketIn($team['workspace'], $node, ['quota_mb' => 1, 'size_bytes' => null]);
    bucketIn($team['workspace'], $node, ['status' => BucketStatus::Deleting, 'quota_mb' => 1, 'size_bytes' => BUCKET_MB]);
    bucketIn($team['workspace'], $node, ['status' => BucketStatus::Locked, 'quota_mb' => 1, 'size_bytes' => BUCKET_MB]);

    $this->artisan('buckets:check-usage')->assertSuccessful();

    expect(AuditEvent::query()->where('action', 'bucket.storage_warning')->count())->toBe(1);
});

test('buckets:lock needs a reason and a ready bucket, and records it; buckets:unlock needs a locked one', function (): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node);

    $this->artisan('buckets:lock', ['bucket' => $bucket->id])->assertFailed();
    $this->artisan('buckets:unlock', ['bucket' => $bucket->id])->assertFailed();
    $this->artisan('buckets:lock', ['bucket' => $bucket->id, '--reason' => 'phishing page'])->assertSuccessful();
    $this->artisan('buckets:lock', ['bucket' => $bucket->id, '--reason' => 'again'])->assertFailed();

    $lock = bucketCommand($bucket, AgentCommandType::LockBucket);
    $audit = AuditEvent::query()->where('action', 'bucket.lock_requested')->sole();

    expect($lock->payload)->toBe(['bucket' => $bucket->s3_bucket, 'access_key_id' => $bucket->access_key_id])
        ->and($lock->agent_node_id)->toBe($node->id)
        ->and($audit->actor_type)->toBe('system')
        ->and($audit->actor_id)->toBe('buckets:lock')
        ->and($audit->metadata['reason'])->toBe('phishing page');

    $lock->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(storageNodeHeaders($node))->postJson("/api/agent/v1/commands/{$lock->id}/complete", ['result' => null])->assertNoContent();

    $this->artisan('buckets:unlock', ['bucket' => $bucket->id, '--reason' => 'cleaned up'])->assertSuccessful();

    expect(bucketCommand($bucket, AgentCommandType::UnlockBucket)->status)->toBe(AgentCommandStatus::Pending)
        ->and(AuditEvent::query()->where('action', 'bucket.unlock_requested')->sole()->metadata['reason'])->toBe('cleaned up');

    $this->artisan('buckets:lock', ['bucket' => 'f0000000-0000-4000-8000-000000000000', '--reason' => 'x'])->assertFailed();
});

test('buckets:reconcile sends CreateBucket with the stored values for ready and locked buckets only', function (): void {
    $node = storageNode();
    $other = storageNode();
    $team = bucketTeam();
    $ready = bucketIn($team['workspace'], $node, ['quota_mb' => 33, 'reserved_quota_mb' => 33]);
    $locked = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Locked]);
    $failed = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Failed]);
    $elsewhere = bucketIn($team['workspace'], $other);
    $rotating = bucketIn($team['workspace'], $node);
    issuedBucketCommand($rotating, AgentCommandType::RotateBucketKey, AgentCommandStatus::Pending);

    $this->artisan('buckets:reconcile', ['--node' => $node->id])->assertSuccessful();

    $create = bucketCommand($ready, AgentCommandType::CreateBucket);

    expect($create->payload['quota_mb'])->toBe(33)
        ->and($create->payload['bucket'])->toBe($ready->s3_bucket)
        ->and(Crypt::decryptString($create->payload[AgentCommandPayloadMaterializer::SECRET_KEY]))->toBe($ready->secret_access_key)
        ->and(AgentCommand::query()->where('bucket_id', $locked->id)->where('type', AgentCommandType::CreateBucket)->count())->toBe(1)
        ->and(AgentCommand::query()->whereIn('bucket_id', [$failed->id, $elsewhere->id, $rotating->id])->where('type', AgentCommandType::CreateBucket)->exists())->toBeFalse();

    // Running again while those are open sends nothing new.
    $this->artisan('buckets:reconcile', ['--bucket' => $ready->id])->assertSuccessful();

    expect(AgentCommand::query()->where('bucket_id', $ready->id)->where('type', AgentCommandType::CreateBucket)->count())->toBe(1);

    // The repeat on the locked bucket completes without unlocking it.
    $lockedCreate = bucketCommand($locked, AgentCommandType::CreateBucket);
    $lockedCreate->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(storageNodeHeaders($node))->postJson("/api/agent/v1/commands/{$lockedCreate->id}/complete", ['result' => null])->assertNoContent();

    expect(bucketStatus($locked))->toBe(BucketStatus::Locked)
        ->and(AuditEvent::query()->where('action', 'bucket.reconciled')->where('subject_id', $locked->id)->exists())->toBeTrue();
});

test('check-usage is scheduled every minute on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'buckets:check-usage'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue();
});
