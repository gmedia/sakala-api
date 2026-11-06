<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use Illuminate\Support\Facades\DB;

final class PruneNotificationsAction
{
    /**
     * Prune notifications older than the retention window.
     *
     * @return array{pruned: int, retention_days: int}
     */
    public function handle(int $retentionDays, bool $dryRun = false, int $batchSize = 1000): array
    {
        $cutoff = now()->subDays($retentionDays);

        if ($dryRun) {
            return [
                'pruned' => DB::table('notifications')->where('created_at', '<', $cutoff)->count(),
                'retention_days' => $retentionDays,
            ];
        }

        $pruned = 0;

        do {
            $deleted = DB::table('notifications')
                ->where('created_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $pruned += $deleted;
        } while ($deleted >= $batchSize);

        return ['pruned' => $pruned, 'retention_days' => $retentionDays];
    }
}
