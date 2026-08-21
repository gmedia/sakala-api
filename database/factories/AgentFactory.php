<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'token_hash' => bcrypt('test-token-'.$this->faker->uuid),
            'token_prefix' => $this->faker->regexify('[A-Za-z0-9]{10}'),
            'status' => 'active',
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
