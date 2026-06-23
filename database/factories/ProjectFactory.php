<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $slug = fake()->unique()->slug(3);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'slug' => $slug,
            'repository_provider' => 'github',
            'repository_url' => "https://github.com/sakala-demo/{$slug}",
            'repository_full_name' => "sakala-demo/{$slug}",
            'branch' => 'main',
            'default_domain' => "{$slug}.run.sakala.localhost",
            'status' => ProjectStatus::Draft,
            'runtime_status' => RuntimeStatus::NotDeployed,
            'detected_port' => null,
            'last_deployed_at' => null,
        ];
    }
}
