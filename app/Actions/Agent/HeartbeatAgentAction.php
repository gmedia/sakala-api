<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Actions\Database\RecordDatabaseSizesAction;
use App\Data\Agent\AgentHeartbeatData;
use App\Models\AgentNode;
use Illuminate\Support\Facades\DB;

final class HeartbeatAgentAction
{
    public function __construct(
        private readonly AssignPendingCommandsAction $assignPendingCommands,
        private readonly ReconcileCrashedWorkloadsAction $reconcileCrashedWorkloads,
        private readonly RecordWorkloadTrafficAction $recordWorkloadTraffic,
        private readonly RecordWorkloadReplicasAction $recordWorkloadReplicas,
        private readonly RecordWorkloadMetricsAction $recordWorkloadMetrics,
        private readonly RecordDatabaseSizesAction $recordDatabaseSizes,
    ) {}

    public function handle(
        AgentNode $agent,
        AgentHeartbeatData $data,
    ): AgentNode {
        $agent = DB::transaction(function () use ($agent, $data): AgentNode {
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
                'protocol_version' => $data->protocolVersion,
                'last_seen_at' => now(),
            ]);

            if ($data->crashedWorkloads !== null) {
                $this->reconcileCrashedWorkloads->handleWithinTransaction($agent, $data->crashedWorkloads);
            }

            if ($data->workloadTraffic !== null) {
                $this->recordWorkloadTraffic->handleWithinTransaction($agent, $data->workloadTraffic);
            }

            if ($data->workloadReplicas !== null) {
                $this->recordWorkloadReplicas->handleWithinTransaction($agent, $data->workloadReplicas);
            }

            if ($data->workloadMetrics !== null) {
                $this->recordWorkloadMetrics->handleWithinTransaction($agent, $data->workloadMetrics);
            }

            if ($data->databaseSizes !== null) {
                $this->recordDatabaseSizes->handleWithinTransaction($agent, $data->databaseSizes);
            }

            return $agent->refresh();
        });

        // A node that just came back (or gained capabilities) should pick up
        // waiting pinned work without waiting for the scheduler sweep.
        $this->assignPendingCommands->handle(onlyFor: $agent, limit: 10);

        return $agent;
    }
}
