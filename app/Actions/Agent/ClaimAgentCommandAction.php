<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ClaimAgentCommandAction
{
    /**
     * Atomically claim a pending command for the requesting agent node.
     * Returns the claimed command, or null when the command is not claimable
     * (conflict with current state or ownership mismatch).
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

                $this->assertClaimable($command, $agent);

                $command->update([
                    'status' => AgentCommandStatus::Claimed,
                    'claimed_at' => now(),
                    'attempts' => $command->attempts + 1,
                    'agent_node_id' => $command->agent_node_id ?? $agent->id,
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
    private function assertClaimable(AgentCommand $command, AgentNode $agent): void
    {
        if ($command->status->value !== AgentCommandStatus::Pending->value) {
            throw $this->conflict($command);
        }

        if ($command->available_at > now()) {
            throw $this->conflict($command);
        }

        if ($command->expires_at !== null && $command->expires_at < now()) {
            throw $this->conflict($command);
        }

        if ($command->agent_node_id !== null && $command->agent_node_id !== $agent->id) {
            throw $this->conflict($command);
        }

        $required = $command->type->requiredCapabilities();
        $nodeCaps = $agent->capabilities ?? [];

        if (collect($required)->intersect($nodeCaps)->isEmpty()) {
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
