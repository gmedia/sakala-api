<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Services\Agent\AgentCommandEligibilityService;
use Illuminate\Support\Facades\DB;

final class ClaimAgentCommandAction
{
    public function __construct(
        private readonly AgentCommandEligibilityService $eligibility,
    ) {}

    /**
     * Atomically claim a pending command for the requesting agent node.
     * Returns the claimed command, or null when the command is not claimable
     * (state/eligibility conflict).
     *
     * The node is re-fetched and re-validated inside the transaction because
     * its state can change between the agent's poll and its claim (e.g. the
     * node went draining or lost capabilities). Polling gives no ownership,
     * so a node that is no longer eligible must fail the claim.
     *
     * @throws CommandConflictException
     */
    public function handle(AgentNode $agent, string $commandId): AgentCommand
    {
        return DB::transaction(function () use ($agent, $commandId): AgentCommand {
            $command = AgentCommand::query()
                ->whereKey($commandId)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-fetch the node under lock: middleware loaded it at the
            // start of the request, and its status may have changed since.
            $node = AgentNode::query()
                ->whereKey($agent->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertClaimable($command, $node);

            $command->update([
                'status' => AgentCommandStatus::Claimed,
                'claimed_at' => now(),
                'attempts' => $command->attempts + 1,
                'agent_node_id' => $command->agent_node_id ?? $node->id,
            ]);

            return $command->fresh();
        });
    }

    /**
     * @throws CommandConflictException
     */
    private function assertClaimable(AgentCommand $command, AgentNode $node): void
    {
        if ($command->status->value !== AgentCommandStatus::Pending->value) {
            throw new CommandConflictException($command);
        }

        if (! $this->eligibility->nodeIsEligibleFor($node, $command->type)) {
            throw new CommandConflictException($command);
        }

        if ($command->available_at > now()) {
            throw new CommandConflictException($command);
        }

        if ($command->expires_at !== null && $command->expires_at < now()) {
            throw new CommandConflictException($command);
        }

        if ($command->agent_node_id !== null && $command->agent_node_id !== $node->id) {
            throw new CommandConflictException($command);
        }
    }
}
