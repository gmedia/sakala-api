<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\AgentNodeStateData;
use App\Models\AgentNode;

final class GetAgentNodeStateAction
{
    /**
     * Return the control-plane desired lifecycle state the agent must adopt
     * before it starts polling. The reported `status` is never consulted.
     */
    public function handle(AgentNode $agent): AgentNodeStateData
    {
        return new AgentNodeStateData(
            desiredState: $agent->desired_state,
        );
    }
}
