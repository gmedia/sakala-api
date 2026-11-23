<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Models\AgentCommand;
use App\Models\ManagedBucket;
use App\Services\Bucket\BucketCommandService;
use App\Support\Bucket\BucketAudit;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * An admin locks a bucket for abuse, or unlocks it again (ADR-021; artisan
 * only in v1). Locking disables the bucket's key on the node, so the
 * application can no longer read or write and presigned URLs stop working;
 * the status turns `locked` once the node reports. Lock needs a ready
 * bucket and unlock a locked one, and neither runs while the other is open.
 */
final class RequestBucketLockAction
{
    public function __construct(
        private readonly BucketCommandService $commands,
    ) {}

    /** @throws DomainException when the bucket is not in the status the request needs, or a lock or unlock is open. */
    public function handle(ManagedBucket $bucket, bool $lock, ?string $reason): AgentCommand
    {
        return DB::transaction(function () use ($bucket, $lock, $reason): AgentCommand {
            /** @var ManagedBucket $locked */
            $locked = ManagedBucket::query()->whereKey($bucket->id)->lockForUpdate()->firstOrFail();
            $required = $lock ? BucketStatus::Ready : BucketStatus::Locked;

            if ($locked->status !== $required) {
                throw new DomainException(sprintf('Bucket %s is %s; %s needs %s.', $locked->id, $locked->status->value, $lock ? 'lock' : 'unlock', $required->value));
            }

            if ($locked->openCommand(AgentCommandType::LockBucket) !== null || $locked->openCommand(AgentCommandType::UnlockBucket) !== null) {
                throw new DomainException(sprintf('Bucket %s already has a lock or unlock on its way.', $locked->id));
            }

            $command = $lock ? $this->commands->lock($locked) : $this->commands->unlock($locked);

            BucketAudit::record(
                $lock ? BucketAudit::LOCK_ACTOR : BucketAudit::UNLOCK_ACTOR,
                $lock ? BucketAudit::LOCK_REQUESTED : BucketAudit::UNLOCK_REQUESTED,
                $locked,
                array_filter([
                    'command_id' => $command->id,
                    'access_key_id' => $locked->access_key_id,
                    'reason' => $reason,
                ], static fn (mixed $value): bool => $value !== null),
            );

            return $command;
        });
    }
}
