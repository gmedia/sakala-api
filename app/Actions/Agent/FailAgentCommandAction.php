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
        $errorCode = mb_substr(
            $this->sanitizeCode($errorCode),
            0,
            self::MAX_ERROR_CODE_LENGTH,
        );
        $errorMessage = mb_substr(
            $this->sanitizeMessage($errorMessage),
            0,
            self::MAX_ERROR_MESSAGE_LENGTH,
        );

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
     * Strip only characters that are unsafe in a diagnostic message: C0
     * controls (except tab, LF, CR), DEL, C1 controls, bidi overrides,
     * and zero-width invisibles. Normal printable text — including
     * punctuation such as ':', '/', '=', '-', '.', '(', ')' — is kept
     * verbatim so diagnostic information is not mangled.
     */
    private function sanitizeMessage(string $message): string
    {
        return preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x80-\x9F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
            '',
            $message,
        ) ?? $message;
    }

    /**
     * Boundary-sanitize a machine-readable error code: keep only a boring
     * token alphabet (letters, digits, dot, underscore, dash) and trim
     * separator characters from the edges. Byte-oriented on purpose, so
     * non-ASCII bytes (including any invalid UTF-8) are dropped without
     * ever failing the pattern.
     */
    private function sanitizeCode(string $code): string
    {
        $code = preg_replace('/[^A-Za-z0-9._-]/', '', $code) ?? $code;

        return trim($code, '._-');
    }
}
