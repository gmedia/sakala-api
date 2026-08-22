<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentNode>
 */
class AgentNodeFactory extends Factory
{
    protected $model = AgentNode::class;

    public function definition(): array
    {
        return [
            'agent_id' => 'agent-'.$this->faker->unique()->uuid(),
            'name' => $this->faker->unique()->word(),
            'token_hash' => bcrypt('test-token-'.$this->faker->uuid),
            'token_prefix' => $this->faker->regexify('[A-Za-z0-9]{10}'),
            'auth_status' => AgentAuthStatus::Active,
            'status' => AgentNodeStatus::Ready,
            'description' => $this->faker->optional()->sentence(),
            'registered_at' => now(),
        ];
    }
}
