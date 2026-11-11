<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LimitProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LimitProfile> */
class LimitProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Profil ####'),
            'max_projects' => 5,
            'max_active_containers' => 3,
            'max_active_deployments' => 2,
            'max_memory_mb' => 512,
            'max_cpu_millis' => 1000,
            'max_builds_per_hour' => null,
        ];
    }
}
