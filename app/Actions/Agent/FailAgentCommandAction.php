<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class FailAgentCommandAction
{
    public const MAX_ERROR_CODE_LENGTH = 64;

    public const MAX_ERROR_MESSAGE_LENGTH = 1000;

    /**
     * Mark a claimed/running command as failed.
     * Returns true when the transition was performed, false when the command
     * is already in terminal Failed state (idempotent repeat).
     *
     * @throws ConflictHttpException when the transition is illegal or the
     *                               command does not belong to the caller.
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

            if ($command->status->value === AgentCommandStatus::Failed->value) {
                return; // idempotent: already failed
            }

            if (! in_array($command->status->value, [
                AgentCommandStatus::Claimed->value,
                AgentCommandStatus::Running->value,
            ], true)) {
                throw $this->conflict($command, 'fail');
            }

            if ($command->agent_node_id !== $agent->id) {
                throw $this->conflict($command, 'fail');
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

    private function conflict(AgentCommand $command, string $operation): ConflictHttpException
    {
        return new ConflictHttpException(
            sprintf('Cannot %s command in %s state.', $operation, $command->status->value),
        );
    }
}
