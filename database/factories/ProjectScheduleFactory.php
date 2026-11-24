<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScheduleHealth;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Services\Schedule\SchedulePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectSchedule>
 */
class ProjectScheduleFactory extends Factory
{
    protected $model = ProjectSchedule::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->bothify('laporan-####'),
            'command' => 'php artisan report:daily',
            'cron_expression' => '0 * * * *',
            'timezone' => SchedulePolicy::DEFAULT_TIMEZONE,
            'timeout_seconds' => SchedulePolicy::DEFAULT_TIMEOUT_SECONDS,
            'enabled' => true,
            'next_run_at' => null,
            'health' => ScheduleHealth::Healthy,
            'consecutive_failures' => 0,
        ];
    }
}
