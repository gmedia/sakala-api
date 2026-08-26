<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\CreateAgentData;
use App\Enums\AgentAuthStatus;
use App\Models\AgentNode;
use App\Models\User;
use Illuminate\Support\Str;

final class ProvisionAgentAction
{
    /** @return array{agent: AgentNode, token: string} */
    public function handle(User $user, CreateAgentData $data): array
    {
        $token = Str::random(64);
        $tokenPrefix = Str::substr($token, 0, 10);

        $agentNode = AgentNode::create([
            'agent_id' => 'agent-'.Str::uuid7(),
            'name' => $data->name,
            'description' => $data->description,
            'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
            'token_prefix' => $tokenPrefix,
            'auth_status' => AgentAuthStatus::Active,
            'registered_at' => now(),
        ]);

        return [
            'agent' => $agentNode,
            'token' => $token,
        ];
    }
}
