<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One replica of one deployment over one hour, rolled up from
 * ProjectMetricMinute by `projects:roll-up-metrics` and pruned after
 * `sakala.metrics.hourly_retention_days`. The current hour is rolled up too,
 * so it fills in as the hour goes.
 *
 * @property int $id
 * @property string $project_id
 * @property string $deployment_id
 * @property int $replica
 * @property Carbon $bucket Start of the hour, UTC.
 * @property int $sample_count
 * @property float $cpu_percent_sum
 * @property float $cpu_percent_max
 * @property int $memory_bytes_sum
 * @property int $memory_bytes_max
 * @property int|null $memory_limit_bytes From the latest minute; null means no limit.
 * @property int $restart_count Cumulative restart count of the latest minute.
 * @property int $restarts Restarts within the hour.
 * @property Carbon $last_sampled_at
 */
#[Fillable([
    'project_id',
    'deployment_id',
    'replica',
    'bucket',
    'sample_count',
    'cpu_percent_sum',
    'cpu_percent_max',
    'memory_bytes_sum',
    'memory_bytes_max',
    'memory_limit_bytes',
    'restart_count',
    'restarts',
    'last_sampled_at',
])]
class ProjectMetricHour extends Model
{
    public const BUCKET_SECONDS = 3600;

    /** The upsert key: one row per replica of a deployment per hour. */
    public const UNIQUE_BY = ['deployment_id', 'replica', 'bucket'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'replica' => 'integer',
            'bucket' => 'datetime',
            'sample_count' => 'integer',
            'cpu_percent_sum' => 'float',
            'cpu_percent_max' => 'float',
            'memory_bytes_sum' => 'integer',
            'memory_bytes_max' => 'integer',
            'memory_limit_bytes' => 'integer',
            'restart_count' => 'integer',
            'restarts' => 'integer',
            'last_sampled_at' => 'datetime',
        ];
    }
}
