<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentCommand> */
class AgentCommandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => null,
            'deployment_id' => null,
            'agent_node_id' => null,
            'type' => AgentCommandType::HealthCheck,
            'status' => AgentCommandStatus::Pending,
            'payload' => [],
            'result' => null,
            'idempotency_key' => (string) Str::uuid(),
            'attempts' => 0,
            'error_code' => null,
            'error_message' => null,
            'available_at' => now(),
            'claimed_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
