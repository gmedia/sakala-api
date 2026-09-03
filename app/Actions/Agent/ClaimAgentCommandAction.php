<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Services\Agent\AgentCommandEligibilityService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ClaimAgentCommandAction
{
    public function __construct(
        private readonly AgentCommandEligibilityService $eligibility,
    ) {}

    /**
     * Atomically claim a pending command for the requesting agent node.
     * Returns the claimed command, or null when the command is not claimable
     * (conflict with current state or ownership mismatch).
     *
     * The node is re-fetched and re-validated inside the transaction because
     * its state can change between the agent's poll and its claim (e.g. the
     * node went draining or lost capabilities). Polling gives no ownership,
     * so a node that is no longer eligible must fail the claim.
     */
    public function handle(AgentNode $agent, string $commandId): ?AgentCommand
    {
        try {
            return DB::transaction(function () use ($agent, $commandId): ?AgentCommand {
                $command = AgentCommand::query()
                    ->whereKey($commandId)
                    ->lockForUpdate()
                    ->first();

                if ($command === null) {
                    abort(404, 'Command not found.');
                }

                // Re-fetch the node under lock: middleware loaded it at the
                // start of the request, and its status may have changed since.
                $node = AgentNode::query()
                    ->whereKey($agent->id)
                    ->lockForUpdate()
                    ->first();

                if ($node === null) {
                    abort(404, 'Agent not found.');
                }

                $this->assertClaimable($command, $node);

                $command->update([
                    'status' => AgentCommandStatus::Claimed,
                    'claimed_at' => now(),
                    'attempts' => $command->attempts + 1,
                    'agent_node_id' => $command->agent_node_id ?? $node->id,
                ]);

                return $command->fresh();
            });
        } catch (ConflictHttpException $e) {
            return null;
        }
    }

    /**
     * @throws ConflictHttpException
     */
    private function assertClaimable(AgentCommand $command, AgentNode $node): void
    {
        if ($command->status->value !== AgentCommandStatus::Pending->value) {
            throw $this->conflict($command);
        }

        if (! $this->eligibility->nodeIsEligibleFor($node, $command->type)) {
            throw $this->conflict($command);
        }

        if ($command->available_at > now()) {
            throw $this->conflict($command);
        }

        if ($command->expires_at !== null && $command->expires_at < now()) {
            throw $this->conflict($command);
        }

        if ($command->agent_node_id !== null && $command->agent_node_id !== $node->id) {
            throw $this->conflict($command);
        }
    }

    private function conflict(AgentCommand $command): ConflictHttpException
    {
        return new ConflictHttpException(
            'Command is not available for claiming.',
        );
    }
}
