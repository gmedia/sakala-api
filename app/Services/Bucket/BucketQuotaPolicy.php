<?php

declare(strict_types=1);

namespace App\Services\Bucket;

use App\Exceptions\Bucket\BucketLimitExceededException;
use App\Exceptions\Bucket\BucketStorageLimitExceededException;
use App\Models\ManagedBucket;
use App\Models\Workspace;
use App\Services\Runtime\PilotRuntimeLimitService;

/**
 * The workspace side of bucket quotas (ADR-021): how many buckets a
 * workspace may hold and how many MB their quotas may reserve together.
 * Callers hold the workspace row lock, so the count and the sum cannot move
 * between the check and their write. Lowering a profile never shrinks a
 * bucket; it only refuses new buckets and increases.
 */
final class BucketQuotaPolicy
{
    /** Quota of a new bucket when the request and the configuration leave it out, in MB. */
    public const DEFAULT_QUOTA_MB = 256;

    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
    ) {}

    /**
     * `sakala.buckets.default_quota_mb`, or DEFAULT_QUOTA_MB when unset or
     * empty, within the bucket quota bounds.
     */
    public function defaultQuotaMb(): int
    {
        $configured = config('sakala.buckets.default_quota_mb');
        $quota = filled($configured) ? (int) $configured : self::DEFAULT_QUOTA_MB;

        return max(ManagedBucket::MIN_QUOTA_MB, min(ManagedBucket::MAX_QUOTA_MB, $quota));
    }

    /**
     * The quota a new bucket of the workspace gets: the requested one, or
     * the default capped at what the workspace has left (at least the
     * smallest quota, which then fails the storage check when nothing is
     * left). Refuses when the count or the reserved storage would pass the
     * workspace's limits.
     *
     * @throws BucketLimitExceededException
     * @throws BucketStorageLimitExceededException
     */
    public function ensureCreateAllowed(Workspace $lockedWorkspace, ?int $requestedMb): int
    {
        $limits = $this->limits->limitsFor($lockedWorkspace);
        $count = ManagedBucket::countIn($lockedWorkspace->id);

        if ($count >= $limits->max_buckets) {
            throw new BucketLimitExceededException($limits->max_buckets, $count);
        }

        $reserved = ManagedBucket::reservedMbIn($lockedWorkspace->id);
        $quota = $requestedMb ?? max(ManagedBucket::MIN_QUOTA_MB, min($this->defaultQuotaMb(), $limits->max_bucket_storage_mb - $reserved));

        $this->ensureFits($limits->max_bucket_storage_mb, $reserved, $quota);

        return $quota;
    }

    /**
     * Refuses raising the bucket's quota to `$quotaMb` when the workspace's
     * reservations without this bucket, plus the new quota, would pass its
     * storage limit. A decrease is always allowed.
     *
     * @throws BucketStorageLimitExceededException
     */
    public function ensureIncreaseAllowed(Workspace $lockedWorkspace, ManagedBucket $bucket, int $quotaMb): void
    {
        if ($quotaMb <= $bucket->quota_mb) {
            return;
        }

        $this->ensureFits(
            $this->limits->limitsFor($lockedWorkspace)->max_bucket_storage_mb,
            ManagedBucket::reservedMbIn($lockedWorkspace->id, except: $bucket->id),
            $quotaMb,
        );
    }

    /** @throws BucketStorageLimitExceededException */
    private function ensureFits(int $limitMb, int $reservedMb, int $requestedMb): void
    {
        if ($reservedMb + $requestedMb > $limitMb) {
            throw new BucketStorageLimitExceededException($limitMb, $reservedMb, $requestedMb);
        }
    }
}
