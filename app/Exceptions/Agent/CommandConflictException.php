<?php

declare(strict_types=1);

namespace App\Exceptions\Agent;

use App\Models\AgentCommand;
use RuntimeException;

/**
 * Thrown when a claim/complete/fail transition is rejected because the
 * command or node is no longer in an eligible state. Carries the command
 * so the API can respond with its current safe state, as required by the
 * agent contract (409 must expose status and terminal_at when relevant,
 * never the deployment payload or credentials).
 */
final class CommandConflictException extends RuntimeException
{
    public function __construct(
        private readonly AgentCommand $command,
    ) {
        parent::__construct('Command is not available for this operation.');
    }

    public function command(): AgentCommand
    {
        return $this->command;
    }
}
