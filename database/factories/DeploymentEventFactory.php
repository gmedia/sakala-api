<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeploymentEventLevel;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeploymentEvent> */
class DeploymentEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'agent_command_id' => null,
            'sequence' => fake()->unique()->numberBetween(1, 1_000_000),
            'level' => DeploymentEventLevel::Info,
            'type' => 'deployment.progress',
            'message' => fake()->sentence(),
            'metadata' => [],
            'occurred_at' => now(),
        ];
    }
}
