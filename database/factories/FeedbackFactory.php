<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeedbackCategory;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'deployment_id' => null,
            'category' => fake()->randomElement(FeedbackCategory::cases()),
            'message' => fake()->paragraph(),
            'consent' => fake()->boolean(),
        ];
    }
}
