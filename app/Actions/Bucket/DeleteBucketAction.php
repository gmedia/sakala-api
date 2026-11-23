<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Exceptions\Bucket\BucketBusyException;
use App\Exceptions\Bucket\BucketInUseException;
use App\Models\AgentCommand;
use App\Models\ManagedBucket;
use App\Models\User;
use App\Services\Bucket\BucketCommandService;
use App\Support\Bucket\BucketAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deleting removes the bucket's objects, key, policy, and bucket on its node
 * at once: the status becomes `deleting`, and the row is soft deleted once
 * the node reports. Only a bucket no project uses can be deleted. A
 * CreateBucket no node has claimed yet is cancelled instead, since nothing
 * exists on the node. A failed bucket gets a DeleteBucket too, because its
 * creation may have half succeeded. Asking again while a delete is on its
 * way changes nothing; asking after a failed delete sends another one.
 */
final class DeleteBucketAction
{
    /** `error_code` of a CreateBucket cancelled because its bucket was deleted first. */
    public const CANCELLED_CREATE_CODE = 'bucket_deleted';

    public function __construct(
        private readonly BucketCommandService $commands,
    ) {}

    /**
     * @return array{bucket: ManagedBucket, removed: bool} `removed` when the row went at once (nothing was on the node).
     *
     * @throws BucketInUseException
     * @throws BucketBusyException
     */
    public function handle(User $actor, ManagedBucket $bucket, ?string $reason = null): array
    {
        return DB::transaction(function () use ($actor, $bucket, $reason): array {
            /** @var ManagedBucket $locked */
            $locked = ManagedBucket::query()->whereKey($bucket->id)->lockForUpdate()->firstOrFail();

            $projects = $locked->projects()->orderBy('name')->get();

            if ($projects->isNotEmpty()) {
                throw new BucketInUseException($projects);
            }

            if ($locked->status === BucketStatus::Deleting && $locked->openCommand(AgentCommandType::DeleteBucket) !== null) {
                return ['bucket' => $locked, 'removed' => false];
            }

            if ($locked->status === BucketStatus::Provisioning) {
                return ['bucket' => $this->cancelProvisioning($actor, $locked, $reason), 'removed' => true];
            }

            $locked->update(['status' => BucketStatus::Deleting]);
            $command = $this->commands->delete($locked);

            BucketAudit::record($actor, BucketAudit::DELETE_REQUESTED, $locked, array_filter([
                'command_id' => $command->id,
                's3_bucket' => $locked->s3_bucket,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null));

            return ['bucket' => $locked, 'removed' => false];
        });
    }

    /**
     * Nothing exists on the node while no node has claimed the CreateBucket,
     * so it is cancelled and the row goes at once. A node already creating it
     * must finish first.
     *
     * @throws BucketBusyException
     */
    private function cancelProvisioning(User $actor, ManagedBucket $bucket, ?string $reason): ManagedBucket
    {
        $create = $bucket->openCommand(AgentCommandType::CreateBucket);

        // Conditional, like claim: whichever writes first wins the command.
        $cancelled = $create === null || AgentCommand::query()
            ->whereKey($create->id)
            ->where('status', AgentCommandStatus::Pending)
            ->update([
                'status' => AgentCommandStatus::Cancelled,
                'error_code' => self::CANCELLED_CREATE_CODE,
                'error_message' => 'The bucket was deleted before a node claimed this command.',
                'updated_at' => now(),
            ]) === 1;

        if (! $cancelled) {
            throw BucketBusyException::creationInProgress();
        }

        $bucket->forceFill([
            'status' => BucketStatus::Deleted,
            'secret_access_key' => null,
            'deleted_at' => now(),
        ])->save();

        $this->commands->eraseSecrets($bucket);

        BucketAudit::record($actor, BucketAudit::DELETED, $bucket, array_filter([
            'cancelled_command_id' => $create?->id,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null));

        return $bucket;
    }
}
