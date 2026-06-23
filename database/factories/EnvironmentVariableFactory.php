<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnvironmentVariable> */
class EnvironmentVariableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'key' => strtoupper(fake()->unique()->bothify('KEY_????_##')),
            'encrypted_value' => fake()->sentence(),
            'is_secret' => true,
        ];
    }
}
