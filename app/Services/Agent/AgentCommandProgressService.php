<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentCommandStatus;
use App\Models\AgentCommand;

final class AgentCommandProgressService
{
    /**
     * The agent never signals Claimed -> Running explicitly; its first
     * accepted report (the `command.claimed` event) is the signal. The caller
     * must already hold the command row lock and have verified ownership.
     */
    public function markRunning(AgentCommand $command): void
    {
        if ($command->status !== AgentCommandStatus::Claimed) {
            return;
        }

        $command->update([
            'status' => AgentCommandStatus::Running,
            'started_at' => now(),
        ]);
    }
}
