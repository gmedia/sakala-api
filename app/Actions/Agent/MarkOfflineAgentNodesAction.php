<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;

final class MarkOfflineAgentNodesAction
{
    /**
     * `offline` is never reported by the agent; the control plane derives it
     * from a missed heartbeat window. The next heartbeat restores whatever
     * status the agent reports. Returns the number of nodes marked offline.
     */
    public function handle(): int
    {
        $threshold = now()->subSeconds((int) config('sakala.agent.offline_after_seconds', 60));

        $staleIds = AgentNode::query()
            ->where('status', '!=', AgentNodeStatus::Offline)
            ->where(function ($query) use ($threshold): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $threshold);
            })
            ->pluck('id');

        $marked = 0;

        foreach ($staleIds as $id) {
            $marked += DB::transaction(function () use ($id, $threshold): int {
                $node = AgentNode::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                // A heartbeat may have landed between the scan and the lock.
                if ($node === null
                    || $node->status === AgentNodeStatus::Offline
                    || ($node->last_seen_at !== null && $node->last_seen_at >= $threshold)) {
                    return 0;
                }

                $previous = $node->status;
                $node->update(['status' => AgentNodeStatus::Offline]);

                AuditEvent::create([
                    'actor_type' => 'system',
                    'actor_id' => 'agent:mark-offline-nodes',
                    'action' => 'agent.node.marked_offline',
                    'subject_type' => AgentNode::class,
                    'subject_id' => $node->id,
                    'metadata' => [
                        'previous_status' => $previous->value,
                        'last_seen_at' => $node->last_seen_at?->toAtomString(),
                    ],
                ]);

                return 1;
            });
        }

        return $marked;
    }
}
