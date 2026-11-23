<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Models\ManagedBucket;
use App\Services\Bucket\BucketCommandService;
use Illuminate\Support\Facades\DB;

/**
 * Sends CreateBucket again, with the stored name, key, secret, and quota, for
 * every ready or locked bucket (of one node, or one bucket), after a storage
 * host was restored or rebuilt (ADR-021). The API is the source of truth for
 * buckets, keys, policies, and quotas; backups hold only objects. CreateBucket
 * never enables a disabled key, so a locked bucket stays locked. A bucket
 * with an open CreateBucket or RotateBucketKey is skipped: either would race
 * with this one over the secret.
 */
final class ReconcileBucketsAction
{
    public function __construct(
        private readonly BucketCommandService $commands,
    ) {}

    /** @return array{sent: int, skipped: int} */
    public function handle(?string $nodeId = null, ?string $bucketId = null): array
    {
        $result = ['sent' => 0, 'skipped' => 0];

        $ids = ManagedBucket::query()
            ->whereIn('status', BucketStatus::provisionedCases())
            ->when($nodeId !== null, static fn ($query) => $query->where('node_id', $nodeId))
            ->when($bucketId !== null, static fn ($query) => $query->whereKey($bucketId))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $sent = DB::transaction(function () use ($id): bool {
                $bucket = ManagedBucket::query()->whereKey($id)->lockForUpdate()->first();

                if ($bucket === null
                    || ! $bucket->status->isProvisioned()
                    || $bucket->secret_access_key === null
                    || $bucket->openCommand(AgentCommandType::CreateBucket) !== null
                    || $bucket->openCommand(AgentCommandType::RotateBucketKey) !== null) {
                    return false;
                }

                $this->commands->create($bucket, $bucket->secret_access_key);

                return true;
            });

            $result[$sent ? 'sent' : 'skipped']++;
        }

        return $result;
    }
}
