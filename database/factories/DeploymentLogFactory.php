<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LogStream;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeploymentLog> */
class DeploymentLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'agent_command_id' => null,
            'sequence' => fake()->unique()->numberBetween(1, 1_000_000),
            'stream' => LogStream::System,
            'message' => fake()->sentence(),
            'recorded_at' => now(),
        ];
    }
}
