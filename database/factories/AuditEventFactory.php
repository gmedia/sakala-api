<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditEvent> */
class AuditEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_type' => 'user',
            'actor_id' => (string) fake()->numberBetween(1, 1000),
            'action' => 'project.viewed',
            'subject_type' => 'project',
            'subject_id' => fake()->uuid(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'metadata' => [],
        ];
    }
}
