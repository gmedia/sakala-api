<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One replica of one deployment over one minute, merged from every sample the
 * node sent for that minute. Written by heartbeats, folded into
 * ProjectMetricHour by `projects:roll-up-metrics`, and pruned after
 * `sakala.metrics.minute_retention_hours`.
 *
 * @property int $id
 * @property string $project_id
 * @property string $deployment_id
 * @property int $replica
 * @property Carbon $bucket Start of the minute, UTC.
 * @property int $sample_count
 * @property float $cpu_percent_sum Sum of the samples' `cpu_percent`; divide by `sample_count` for the average.
 * @property float $cpu_percent_max
 * @property int $memory_bytes_sum Sum of the samples' `memory_bytes`; divide by `sample_count` for the average.
 * @property int $memory_bytes_max
 * @property int|null $memory_limit_bytes From the latest sample; null means no limit.
 * @property int $restart_count Highest cumulative restart count reported in the minute.
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
    'last_sampled_at',
])]
class ProjectMetricMinute extends Model
{
    public const TABLE = 'project_metric_minutes';

    public const BUCKET_SECONDS = 60;

    /** The upsert key: one row per replica of a deployment per minute. */
    public const UNIQUE_BY = ['deployment_id', 'replica', 'bucket'];

    public $timestamps = false;

    protected $table = self::TABLE;

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
            'last_sampled_at' => 'datetime',
        ];
    }
}
