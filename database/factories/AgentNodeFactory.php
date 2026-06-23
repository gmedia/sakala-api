<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentNode> */
class AgentNodeFactory extends Factory
{
    public function definition(): array
    {
        $token = Str::random(40);

        return [
            'agent_id' => fake()->unique()->slug(3),
            'name' => fake()->city().' Runtime',
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 8),
            'status' => AgentNodeStatus::Ready,
            'hostname' => fake()->domainWord(),
            'runtime_network' => 'sakala-runtime',
            'capabilities' => ['docker', 'caddy'],
            'metadata' => ['version' => '0.1.0'],
            'registered_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
