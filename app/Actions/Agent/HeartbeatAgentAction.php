<?php

namespace App\Actions\Agent;

use App\Data\Agent\HearthbeatAgentData;
use App\Models\AgentNode;
use Illuminate\Support\Facades\DB;

final class HeartbeatAgentAction
{
    public function handle(
        AgentNode $agent,
        HearthbeatAgentData $data,
    ): AgentNode
    {
        return DB::transaction(function () use ($agent, $data): AgentNode {
            /** @var AgentNode $agent */
            $agent = AgentNode::query()
                ->lockForUpdate()
                ->findOrFail($agent->id);

            $agent->update([
                'status' => $data->status,
                'hostname' => $data->hostname,
                'runtime_network' => $data->runtimeNetwork,
                'capabilities' => $data->capabilities,
                'metadata' => $data->metadata,
                'last_seen_at' => now(),
            ]);

            return $agent->refresh();
        });
    }
}