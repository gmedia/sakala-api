<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentStatus;
use App\Models\Agent;

final class RevokeAgentAction
{
    public function handle(Agent $agent): void
    {
        $agent->update([
            'status' => AgentStatus::Revoked,
        ]);
    }
}
