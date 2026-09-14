<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UsageSignalType;
use App\Models\UsageSignalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageSignalRecord>
 */
class UsageSignalRecordFactory extends Factory
{
    protected $model = UsageSignalRecord::class;

    public function definition(): array
    {
        return [
            'signal_type' => $this->faker->randomElement(
                array_column(UsageSignalType::cases(), 'value')
            ),
            'count' => $this->faker->numberBetween(1, 100),
            'scope' => $this->faker->randomElement(['global', 'user', 'project']),
            'scope_id' => $this->faker->optional()->uuid(),
            'tags' => $this->faker->optional()->words(3, true),
            'collected_at' => now()->subHours(
                $this->faker->numberBetween(0, 48)
            ),
        ];
    }
}
