<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Enums\AgentCommandType;
use App\Exceptions\Bucket\BucketBusyException;
use App\Exceptions\Bucket\BucketCapacityUnavailableException;
use App\Exceptions\Bucket\BucketNotReadyException;
use App\Exceptions\Bucket\BucketStorageLimitExceededException;
use App\Models\AgentNode;
use App\Models\ManagedBucket;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Bucket\BucketCommandService;
use App\Services\Bucket\BucketQuotaPolicy;
use App\Support\Bucket\BucketAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames a bucket and changes its quota. A rename never touches the node.
 * A quota change needs a ready or locked bucket and no open SetBucketQuota;
 * an increase must fit the workspace's storage limit (admins excepted) and
 * the bucket's own node, both without the bucket's own reservation. The
 * reservation becomes the larger of the old and the new quota until the
 * node reports, so a decrease frees nothing early and an increase is held
 * before the node applies it.
 *
 * Locks: the workspace row first, as every path that reads the workspace's
 * reserved storage takes it, then the bucket's node (for a quota change), then
 * the bucket row.
 */
final class UpdateBucketAction
{
    public function __construct(
        private readonly AgentNodeSchedulerService $scheduler,
        private readonly BucketCommandService $commands,
        private readonly BucketQuotaPolicy $quotas,
    ) {}

    /**
     * @return array{bucket: ManagedBucket, quota_change_requested: bool}
     *
     * @throws BucketNotReadyException
     * @throws BucketBusyException
     * @throws BucketStorageLimitExceededException
     * @throws BucketCapacityUnavailableException
     */
    public function handle(User $actor, ManagedBucket $bucket, ?string $name, ?int $quotaMb): array
    {
        return DB::transaction(function () use ($actor, $bucket, $name, $quotaMb): array {
            /** @var Workspace|null $workspace */
            $workspace = $bucket->workspace_id === null ? null : Workspace::query()->whereKey($bucket->workspace_id)->lockForUpdate()->first();
            // A bucket never moves, so its node is known before the bucket row is locked.
            $node = $quotaMb === null ? null : $this->scheduler->lockBucketNode($bucket->node_id);
            /** @var ManagedBucket $locked */
            $locked = ManagedBucket::query()->whereKey($bucket->id)->lockForUpdate()->firstOrFail();

            if ($name !== null && $name !== $locked->name) {
                $this->rename($actor, $locked, $name);
            }

            $requested = $quotaMb !== null && $quotaMb !== $locked->quota_mb
                && $node !== null
                && $this->changeQuota($actor, $workspace, $node, $locked, $quotaMb);

            return ['bucket' => $locked, 'quota_change_requested' => $requested];
        });
    }

    private function rename(User $actor, ManagedBucket $bucket, string $name): void
    {
        if (ManagedBucket::query()->where('workspace_id', $bucket->workspace_id)->where('name', $name)->whereKeyNot($bucket->id)->exists()) {
            throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => __('validation.attributes.name')])]]);
        }

        $from = $bucket->name;
        $bucket->update(['name' => $name]);

        BucketAudit::record($actor, BucketAudit::RENAMED, $bucket, ['from' => $from, 'to' => $name]);
    }

    /**
     * @throws BucketNotReadyException
     * @throws BucketBusyException
     * @throws BucketStorageLimitExceededException
     * @throws BucketCapacityUnavailableException
     */
    private function changeQuota(User $actor, ?Workspace $workspace, AgentNode $lockedNode, ManagedBucket $bucket, int $quotaMb): bool
    {
        if (! $bucket->status->isProvisioned()) {
            throw new BucketNotReadyException($bucket->status);
        }

        if ($bucket->openCommand(AgentCommandType::SetBucketQuota) !== null) {
            throw BucketBusyException::quotaChangeInProgress();
        }

        if ($quotaMb > $bucket->quota_mb) {
            if ($workspace !== null && ! $actor->isAdmin()) {
                $this->quotas->ensureIncreaseAllowed($workspace, $bucket, $quotaMb);
            }

            $this->scheduler->ensureBucketNodeFits($lockedNode, $quotaMb, $bucket->id);
        }

        $from = $bucket->quota_mb;
        $bucket->update(['reserved_quota_mb' => max($bucket->quota_mb, $quotaMb)]);
        $command = $this->commands->setQuota($bucket, $quotaMb);

        BucketAudit::record($actor, BucketAudit::QUOTA_CHANGE_REQUESTED, $bucket, [
            'command_id' => $command->id,
            'from_mb' => $from,
            'to_mb' => $quotaMb,
        ]);

        return true;
    }
}
