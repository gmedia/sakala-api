<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GithubInstallationStatus;
use App\Models\GithubInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubInstallation>
 */
class GithubInstallationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'github_installation_id' => fake()->unique()->numberBetween(1, PHP_INT_MAX),
            'account_id' => fake()->unique()->numberBetween(1, PHP_INT_MAX),
            'account_login' => fake()->userName(),
            'account_type' => 'User',
            'repository_selection' => 'selected',
            'permissions' => ['contents' => 'read'],
            'status' => GithubInstallationStatus::Active,
        ];
    }
}
