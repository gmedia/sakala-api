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
     * The node is re-fetched (not the middleware's copy) because its state
     * can change between the agent's poll and its claim (e.g. the node went
     * draining or lost capabilities). Polling gives no ownership, so a node
     * that is no longer eligible must fail the claim.
     *
     * The transition is a single guarded UPDATE (WHERE status = Pending) —
     * the atomic primitive endorsed by the agent contract. No two processes
     * can ever flip the same row Pending -> Claimed: the winner observes one
     * affected row, every loser observes zero and conflicts. This is safe on
     * both PostgreSQL and SQLite without relying on row-lock semantics.
     *
     * @throws CommandConflictException
     */
    public function handle(AgentNode $agent, string $commandId): AgentCommand
    {
        return DB::transaction(function () use ($agent, $commandId): AgentCommand {
            // Re-read the node: middleware loaded it at the start of the
            // request, and a heartbeat may have changed its status or
            // capabilities since.
            $node = AgentNode::query()
                ->whereKey($agent->id)
                ->firstOrFail();

            $command = AgentCommand::query()
                ->whereKey($commandId)
                ->firstOrFail();

            $this->assertClaimable($command, $node);

            $updated = AgentCommand::query()
                ->whereKey($command->id)
                ->where('status', AgentCommandStatus::Pending->value)
                ->update([
                    'status' => AgentCommandStatus::Claimed->value,
                    'claimed_at' => now(),
                    'attempts' => $command->attempts + 1,
                    'agent_node_id' => $command->agent_node_id ?? $node->id,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                // Lost the race: another process claimed the row first.
                throw new CommandConflictException($command->fresh());
            }

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
