<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deployment> */
class DeploymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'requested_by' => User::factory(),
            'agent_node_id' => null,
            'sequence' => fake()->unique()->numberBetween(1, 1_000_000),
            'status' => DeploymentStatus::Queued,
            'trigger' => DeploymentTrigger::Manual,
            'branch' => 'main',
            'commit_sha' => fake()->optional()->sha1(),
            'commit_message' => fake()->optional()->sentence(),
            'image_reference' => null,
            'failure_code' => null,
            'failure_summary' => null,
            'started_at' => null,
            'finished_at' => null,
            'cancelled_at' => null,
        ];
    }
}
