<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Data\Valkey\ValkeyUsageData;
use App\Models\AgentNode;
use App\Models\ManagedValkey;
use App\Support\Database\CaseUpdate;

/**
 * Store the reading a Valkey node reported for the stores it runs
 * (`metadata.valkeys[]`, ADR-023). Only the node a store lives on speaks for
 * it, stores being deleted included, and an ID the API does not know on that
 * node is ignored. One read and at most one write per heartbeat, whatever
 * the length of the list; `usage_checked_at` moves for every listed store,
 * so a stale value is visible. The 80 % notice is the usage sweep's
 * (CheckValkeyUsageAction), outside the heartbeat.
 *
 * Called right after the heartbeat locks its node and before it touches any
 * project, so the order is node, then store, then project, as on every other
 * Valkey path.
 */
final class RecordValkeyUsageAction
{
    /**
     * @param  array<string, ValkeyUsageData>  $readings  Keyed by lowercase store ID.
     */
    public function handleWithinTransaction(AgentNode $agent, array $readings): void
    {
        if ($readings === []) {
            return;
        }

        $ids = ManagedValkey::withTrashed()
            ->where('node_id', $agent->id)
            ->whereIn('id', array_keys($readings))
            ->orderBy('id')
            ->pluck('id');

        $checkedAt = (new ManagedValkey)->fromDateTime(now());
        $rows = [];

        foreach ($ids as $id) {
            $reading = $readings[strtolower((string) $id)] ?? null;

            if ($reading === null) {
                continue;
            }

            $rows[(string) $id] = [
                'used_memory_bytes' => $reading->usedMemory,
                'evicted_keys' => $reading->evictedKeys,
                'errorstat_oom' => $reading->errorstatOom,
                'connected_clients' => $reading->connectedClients,
                'aof_last_write_status' => $reading->aofLastWriteStatus,
                'container_status' => $reading->status,
                'restart_count' => $reading->restartCount,
                'usage_checked_at' => $checkedAt,
            ];
        }

        CaseUpdate::apply('valkeys', $rows, [
            'used_memory_bytes' => 'bigint',
            'evicted_keys' => 'bigint',
            'errorstat_oom' => 'bigint',
            'connected_clients' => 'integer',
            'aof_last_write_status' => 'varchar',
            'container_status' => 'varchar',
            'restart_count' => 'integer',
            'usage_checked_at' => 'timestamptz',
        ]);
    }
}
