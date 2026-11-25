<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Services\Agent\AgentNodeSchedulerService;
use Illuminate\Support\Facades\DB;

final class AssignPendingCommandsAction
{
    public function __construct(
        private readonly AgentNodeSchedulerService $scheduler,
    ) {}

    /**
     * Give unassigned pinned commands a target node. Runs from the scheduler
     * and after a heartbeat, so a node that just became eligible picks up
     * waiting work within one heartbeat. Commands are locked one at a time;
     * a command that was assigned or claimed meanwhile is skipped. Returns
     * the number of commands assigned. Database and bucket commands are never
     * here: they are pinned to the resource's node when created, and only
     * that node can run them.
     */
    public function handle(?AgentNode $onlyFor = null, int $limit = 50): int
    {
        $pinnedTypes = collect(AgentCommandType::cases())
            ->filter(fn (AgentCommandType $type): bool => $type->isPinnedAtCreation() && ! $type->isNodeLevel() && ! $type->isManagedResource())
            ->map(fn (AgentCommandType $type): string => $type->value)
            ->values()
            ->all();

        $candidateIds = AgentCommand::query()
            ->where('status', AgentCommandStatus::Pending)
            ->whereNull('agent_node_id')
            ->whereIn('type', $pinnedTypes)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('available_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        $assigned = 0;

        foreach ($candidateIds as $commandId) {
            $assigned += DB::transaction(function () use ($commandId, $onlyFor): int {
                $command = AgentCommand::query()
                    ->whereKey($commandId)
                    ->lockForUpdate()
                    ->first();

                if ($command === null
                    || $command->status !== AgentCommandStatus::Pending
                    || $command->agent_node_id !== null) {
                    return 0;
                }

                // A deployment of several replicas waits for a node that runs
                // them all, one with a release command or workers for a node
                // that runs them; an older one would quietly run one replica,
                // or skip them. One that joins a Valkey store's network waits
                // for the store's node (ADR-023).
                $node = $this->scheduler->selectNodeFor(
                    $command->type,
                    $command->project,
                    $command->requestedReplicas(),
                    $command->requestsReleaseCommand(),
                    $command->requestsWorkerProcesses(),
                    $command->requestedValkeyNodeId(),
                );

                if ($node === null || ($onlyFor !== null && $node->id !== $onlyFor->id)) {
                    return 0;
                }

                $command->update(['agent_node_id' => $node->id]);

                if ($command->type === AgentCommandType::DeployProject && $command->deployment_id !== null) {
                    Deployment::query()
                        ->whereKey($command->deployment_id)
                        ->whereNull('agent_node_id')
                        ->update(['agent_node_id' => $node->id]);
                }

                return 1;
            });
        }

        return $assigned;
    }
}
