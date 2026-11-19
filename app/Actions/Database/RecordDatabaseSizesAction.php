<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Data\Agent\DatabaseSizesData;
use App\Models\AgentNode;
use App\Models\ManagedDatabase;
use App\Support\Database\CaseUpdate;

/**
 * Store the sizes a database node reported for the databases it hosts. Only
 * the node a database lives on speaks for it, and a name the API does not
 * know on that node is ignored. One read and at most one write per heartbeat,
 * whatever the length of the list; `size_checked_at` moves for every listed
 * database, so a stale size is visible.
 */
final class RecordDatabaseSizesAction
{
    public function handleWithinTransaction(AgentNode $agent, DatabaseSizesData $report): void
    {
        if ($report->bytesByName === []) {
            return;
        }

        $databases = ManagedDatabase::query()
            ->where('node_id', $agent->id)
            ->whereIn('pg_database', array_map('strval', array_keys($report->bytesByName)))
            ->get(['id', 'pg_database']);

        $checkedAt = (new ManagedDatabase)->fromDateTime(now());
        $rows = [];

        foreach ($databases as $database) {
            $rows[$database->id] = [
                'size_bytes' => $report->bytesByName[$database->pg_database],
                'size_checked_at' => $checkedAt,
            ];
        }

        CaseUpdate::apply('databases', $rows, ['size_bytes' => 'bigint', 'size_checked_at' => 'timestamptz']);
    }
}
