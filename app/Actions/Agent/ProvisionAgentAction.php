<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\CreateAgentData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use App\Models\User;
use Illuminate\Support\Str;
use App\Policies\AgentNodePolicy;

final class ProvisionAgentAction
{
    public function handle(User $user, CreateAgentData $data): AgentNode
    {
        $token = Str::random(64);
        $tokenPrefix = Str::substr($token, 0, 10);

        return AgentNode::create([
            'agent_id' => 'agent-'.Str::uuid(),
            'name' => $data->name,
            'description' => $data->description,
            'token_hash' => bcrypt($token),
            'token_prefix' => $tokenPrefix,
            'auth_status' => AgentAuthStatus::Active,
            'status' => AgentNodeStatus::Ready,
            'registered_at' => now(),
        ]);
    }

    /**
     * Generate a new token for an agent (used during provisioning).
     */
    public function generateToken(): string
    {
        return Str::random(64);
    }
}
