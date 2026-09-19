<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\UsageSignalRecord;
use Illuminate\Support\Facades\DB;

final class PruneUsageSignalsAction
{
    /**
     * Prune usage signal records older than the retention window.
     *
     * @return array{pruned: int, retention_days: int}
     */
    public function handle(
        int $retentionDays,
        bool $dryRun = false,
        int $batchSize = 1000,
    ): array {
        $cutoff = now()->subDays($retentionDays);

        if ($dryRun) {
            $count = UsageSignalRecord::query()
                ->where('collected_at', '<', $cutoff)
                ->count();

            return ['pruned' => $count, 'retention_days' => $retentionDays];
        }

        $pruned = 0;

        do {
            $deleted = DB::table('usage_signal_records')
                ->where('collected_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $pruned += $deleted;
        } while ($deleted >= $batchSize);

        return ['pruned' => $pruned, 'retention_days' => $retentionDays];
    }
}
