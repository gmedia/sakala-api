<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\AgentHeartbeatData;
use App\Models\AgentNode;
use Illuminate\Support\Facades\DB;

final class HeartbeatAgentAction
{
    public function handle(
        AgentNode $agent,
        AgentHeartbeatData $data,
    ): AgentNode {
        return DB::transaction(function () use ($agent, $data): AgentNode {
            /** @var AgentNode $agent */
            $agent = AgentNode::query()
                ->lockForUpdate()
                ->findOrFail($agent->id);

            $agent->update([
                'hostname' => $data->hostname,
                'runtime_network' => $data->runtimeNetwork,
                'capabilities' => $data->capabilities,
                'metadata' => $data->metadata,
                'status' => $data->status,
                'last_seen_at' => now(),
            ]);

            return $agent->refresh();
        });
    }
}
