<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\CreateAgentData;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Str;

final class ProvisionAgentAction
{
    public function handle(User $user, CreateAgentData $data): Agent
    {
        $token = Str::random(64);
        $tokenPrefix = Str::substr($token, 0, 10);

        return Agent::create([
            'name' => $data->name,
            'description' => $data->description,
            'token_hash' => bcrypt($token),
            'token_prefix' => $tokenPrefix,
            'status' => AgentStatus::Active,
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
