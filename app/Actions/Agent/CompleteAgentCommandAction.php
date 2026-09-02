<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CompleteAgentCommandAction
{
    /**
     * Mark a claimed/running command as succeeded.
     * Returns true when the transition was performed, false when the command
     * is already in terminal Succeeded state (idempotent repeat).
     *
     * @param  array<string, mixed>|null  $result
     *
     * @throws ConflictHttpException when the transition is illegal or the
     *                               command does not belong to the caller.
     */
    public function handle(AgentNode $agent, string $commandId, ?array $result = null): bool
    {
        DB::transaction(function () use ($agent, $commandId, $result): void {
            $command = AgentCommand::query()
                ->whereKey($commandId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($command->status->value === AgentCommandStatus::Succeeded->value) {
                return; // idempotent: already succeeded
            }

            if (! in_array($command->status->value, [
                AgentCommandStatus::Claimed->value,
                AgentCommandStatus::Running->value,
            ], true)) {
                throw $this->conflict($command, 'complete');
            }

            if ($command->agent_node_id !== $agent->id) {
                throw $this->conflict($command, 'complete');
            }

            $command->update([
                'status' => AgentCommandStatus::Succeeded,
                'completed_at' => now(),
                'result' => $result,
            ]);
        });

        // Reached here only if not already Succeeded (idempotent path returns early)
        return true;
    }

    private function conflict(AgentCommand $command, string $operation): ConflictHttpException
    {
        return new ConflictHttpException(
            sprintf('Cannot %s command in %s state.', $operation, $command->status->value),
        );
    }
}
