<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Data\Agent\BucketSizesData;
use App\Models\AgentNode;
use App\Models\ManagedBucket;
use App\Support\Database\CaseUpdate;

/**
 * Store the usage a storage node reported for the buckets it hosts. Only
 * the node a bucket lives on speaks for it, buckets being deleted included,
 * and a name the API does not know on that node is ignored. One read and at
 * most one write per heartbeat, whatever the length of the list;
 * `usage_checked_at` moves for every listed bucket, so a stale value is
 * visible.
 */
final class RecordBucketSizesAction
{
    public function handleWithinTransaction(AgentNode $agent, BucketSizesData $report): void
    {
        if ($report->bytesByName === []) {
            return;
        }

        $buckets = ManagedBucket::query()
            ->where('node_id', $agent->id)
            ->whereIn('s3_bucket', array_map('strval', array_keys($report->bytesByName)))
            ->get(['id', 's3_bucket']);

        $checkedAt = (new ManagedBucket)->fromDateTime(now());
        $rows = [];

        foreach ($buckets as $bucket) {
            $rows[$bucket->id] = [
                'size_bytes' => $report->bytesByName[$bucket->s3_bucket],
                'usage_checked_at' => $checkedAt,
            ];
        }

        CaseUpdate::apply('buckets', $rows, ['size_bytes' => 'bigint', 'usage_checked_at' => 'timestamptz']);
    }
}
