<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentAuthStatus;
use App\Models\AgentNode;

final class RevokeAgentAction
{
    public function handle(AgentNode $agent): void
    {
        $agent->update([
            'auth_status' => AgentAuthStatus::Revoked,
        ]);
    }
}
