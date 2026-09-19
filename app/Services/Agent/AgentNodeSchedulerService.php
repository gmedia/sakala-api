<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\DeploymentStatus;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Pick the runtime node a pinned command is created for. The control plane
 * decides the target before the command is ever offered, so secrets are only
 * materialised for one authenticated node and lifecycle commands land on the
 * node that owns the workload.
 */
final class AgentNodeSchedulerService
{
    public function __construct(
        private readonly AgentCommandEligibilityService $eligibility,
    ) {}

    /**
     * Return the node that should execute a command of the given type for the
     * project, or null when no node can take it right now (the command then
     * stays unassigned until a later sweep or heartbeat assigns it).
     */
    public function selectNodeFor(AgentCommandType $type, ?Project $project = null): ?AgentNode
    {
        $candidates = $this->eligibleNodes($type);

        if ($candidates->isEmpty()) {
            return null;
        }

        // Routes, images, and containers are node-local: keep a project on the
        // node that currently serves it so stop/finalization commands can
        // reach the running workload.
        if ($project !== null) {
            $currentNodeId = Deployment::query()
                ->where('project_id', $project->id)
                ->where('status', DeploymentStatus::Succeeded)
                ->whereNotNull('agent_node_id')
                ->orderByDesc('sequence')
                ->value('agent_node_id');

            $sticky = $currentNodeId === null ? null : $candidates->firstWhere('id', $currentNodeId);

            if ($sticky !== null) {
                return $sticky;
            }
        }

        return $candidates
            ->sortBy([
                ['in_flight_commands_count', 'asc'],
                ['last_seen_at', 'desc'],
            ])
            ->first();
    }

    /**
     * Nodes that may execute the command type right now, freshly heartbeated
     * and with capability checked through the shared eligibility policy.
     *
     * @return Collection<int, AgentNode>
     */
    private function eligibleNodes(AgentCommandType $type): Collection
    {
        $offlineAfter = (int) config('sakala.agent.offline_after_seconds', 60);

        /** @var \Illuminate\Database\Eloquent\Collection<int, AgentNode> $nodes */
        $nodes = AgentNode::query()
            ->where('auth_status', AgentAuthStatus::Active)
            ->where('desired_state', AgentNodeDesiredState::Active)
            ->whereIn('status', $this->eligibility->activeNodeStatuses())
            ->whereIn('protocol_version', $this->eligibility->supportedProtocolVersions())
            ->where('last_seen_at', '>=', now()->subSeconds($offlineAfter))
            ->withCount([
                'commands as in_flight_commands_count' => function ($query): void {
                    $query->whereIn('status', [
                        AgentCommandStatus::Claimed,
                        AgentCommandStatus::Running,
                    ]);
                },
            ])
            ->get();

        return $nodes->filter(
            fn (AgentNode $node): bool => $this->eligibility->nodeIsEligibleFor($node, $type),
        )->values();
    }
}
