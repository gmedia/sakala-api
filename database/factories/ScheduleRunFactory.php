<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScheduleRunStatus;
use App\Enums\ScheduleRunTrigger;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;
use App\Services\Schedule\SchedulePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleRun>
 */
class ScheduleRunFactory extends Factory
{
    protected $model = ScheduleRun::class;

    public function definition(): array
    {
        return [
            'schedule_id' => ProjectSchedule::factory(),
            'project_id' => fn (array $attributes): string => (string) ProjectSchedule::query()->whereKey($attributes['schedule_id'])->value('project_id'),
            'trigger' => ScheduleRunTrigger::Scheduled,
            'scheduled_for' => now()->startOfMinute(),
            'status' => ScheduleRunStatus::Succeeded,
            'command' => 'php artisan report:daily',
            'timeout_seconds' => SchedulePolicy::DEFAULT_TIMEOUT_SECONDS,
            'finished_at' => now(),
        ];
    }
}
