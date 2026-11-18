<?php

declare(strict_types=1);

use App\Models\Deployment;
use App\Models\ProjectMetricHour;
use App\Models\ProjectMetricMinute;
use Carbon\CarbonImmutable;

/**
 * A stored minute row of one replica with one sample, unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 */
function metricMinuteRow(Deployment $deployment, DateTimeInterface|string $bucket, array $overrides = []): ProjectMetricMinute
{
    $bucket = is_string($bucket) ? CarbonImmutable::parse($bucket, 'UTC') : CarbonImmutable::instance($bucket)->utc();

    return ProjectMetricMinute::query()->create([
        'project_id' => $deployment->project_id,
        'deployment_id' => $deployment->id,
        'replica' => 0,
        'bucket' => $bucket,
        'sample_count' => 1,
        'cpu_percent_sum' => 10.0,
        'cpu_percent_max' => 10.0,
        'memory_bytes_sum' => 1000,
        'memory_bytes_max' => 1000,
        'memory_limit_bytes' => 4000,
        'restart_count' => 0,
        'last_sampled_at' => $bucket->addSeconds(30),
        ...$overrides,
    ]);
}

/**
 * A stored hourly row of one replica, unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 */
function metricHourRow(Deployment $deployment, DateTimeInterface|string $bucket, array $overrides = []): ProjectMetricHour
{
    $bucket = is_string($bucket) ? CarbonImmutable::parse($bucket, 'UTC') : CarbonImmutable::instance($bucket)->utc();

    return ProjectMetricHour::query()->create([
        'project_id' => $deployment->project_id,
        'deployment_id' => $deployment->id,
        'replica' => 0,
        'bucket' => $bucket,
        'sample_count' => 60,
        'cpu_percent_sum' => 600.0,
        'cpu_percent_max' => 10.0,
        'memory_bytes_sum' => 60000,
        'memory_bytes_max' => 1000,
        'memory_limit_bytes' => 4000,
        'restart_count' => 0,
        'restarts' => 0,
        'last_sampled_at' => $bucket->addMinutes(59),
        ...$overrides,
    ]);
}
