<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use Illuminate\Support\Facades\DB;

final class FailAgentCommandAction
{
    public const MAX_ERROR_CODE_LENGTH = 64;

    public const MAX_ERROR_MESSAGE_LENGTH = 1000;

    /**
     * Mark a claimed/running command as failed.
     * Returns true when the transition was performed, false when the command
     * is already in terminal Failed state (idempotent repeat).
     *
     * @throws CommandConflictException when the transition is illegal or the
     *                                  command does not belong to the caller.
     */
    public function handle(
        AgentNode $agent,
        string $commandId,
        string $errorCode,
        string $errorMessage,
    ): bool {
        $errorCode = mb_substr($errorCode, 0, self::MAX_ERROR_CODE_LENGTH);
        $errorMessage = $this->sanitize(mb_substr($errorMessage, 0, self::MAX_ERROR_MESSAGE_LENGTH));

        DB::transaction(function () use ($agent, $commandId, $errorCode, $errorMessage): void {
            $command = AgentCommand::query()
                ->whereKey($commandId)
                ->lockForUpdate()
                ->firstOrFail();

            // Ownership is checked before the idempotent shortcut: a retry
            // is only idempotent for the agent that claimed the command.
            if ($command->agent_node_id !== $agent->id) {
                throw new CommandConflictException($command);
            }

            if ($command->status->value === AgentCommandStatus::Failed->value) {
                return; // idempotent: already failed
            }

            if (! in_array($command->status->value, [
                AgentCommandStatus::Claimed->value,
                AgentCommandStatus::Running->value,
            ], true)) {
                throw new CommandConflictException($command);
            }

            $command->update([
                'status' => AgentCommandStatus::Failed,
                'failed_at' => now(),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);
        });

        return true;
    }

    /**
     * Strip control characters (except newline and tab) to prevent log pollution.
     */
    private function sanitize(string $message): string
    {
        return preg_replace('/[^\p{L}\p{N}\s\x09\x0A\x0D]/u', '', $message) ?? $message;
    }
}
