<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectProcess>
 */
class ProjectProcessFactory extends Factory
{
    protected $model = ProjectProcess::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->bothify('worker-####'),
            'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600',
            'replicas' => ProjectProcess::DEFAULT_REPLICAS,
            'memory_mb' => null,
            'cpu_millis' => null,
        ];
    }
}
