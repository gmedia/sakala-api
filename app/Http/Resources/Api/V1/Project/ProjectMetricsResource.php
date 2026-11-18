<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Data\Project\ProjectMetricsData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ProjectMetricsData $resource
 */
final class ProjectMetricsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            /** The range asked for: `1h`, `6h`, `24h`, `7d`, or `30d`. */
            'range' => $data->range->value,
            /** Width of one point: 60 for 1h, 300 for 6h, 900 for 24h, 3600 for 7d, 14400 for 30d. */
            'step_seconds' => $data->stepSeconds,
            /**
             * Summed across replicas. Each series has a point for every step with data, oldest first; a step without
             * data has no point, so consecutive `t` more than `step_seconds` apart are a gap. `t` is the start of the
             * step (ISO 8601, UTC). All series share the same `t` values.
             */
            'series' => [
                /**
                 * Percent of one CPU core, summed across replicas, so it can exceed 100. `avg` is the mean over the
                 * step, `max` the highest per-minute (7d, 30d: per-hour) peak sum.
                 *
                 * @var list<array{t: string, avg: float, max: float}>
                 */
                'cpu_percent' => array_map(fn (array $point): array => [...$point, 't' => $this->time($point['t'])], $data->cpuPercent),
                /**
                 * Memory in bytes, summed across replicas, `avg` and `max` like `cpu_percent`.
                 *
                 * @var list<array{t: string, avg: int, max: int}>
                 */
                'memory_bytes' => array_map(fn (array $point): array => [...$point, 't' => $this->time($point['t'])], $data->memoryBytes),
                /**
                 * Summed memory limit of the replicas in the latest data of the range, in bytes. Null when there is
                 * no data or a replica runs without a limit.
                 */
                'memory_limit_bytes' => $data->memoryLimitBytes,
                /**
                 * Replicas that reported in the step (the most in any minute, or hour for 7d and 30d).
                 *
                 * @var list<array{t: string, count: int}>
                 */
                'replicas' => array_map(fn (array $point): array => [...$point, 't' => $this->time($point['t'])], $data->replicas),
                /**
                 * Container restarts in the step, summed across replicas.
                 *
                 * @var list<array{t: string, count: int}>
                 */
                'restarts' => array_map(fn (array $point): array => [...$point, 't' => $this->time($point['t'])], $data->restarts),
                /**
                 * Only with `per_replica=1`: the same series for each replica index, lowest first. Replicas of a
                 * deployment that replaced another in the range share an index with the replica they replaced.
                 *
                 * @var list<array{replica: int, cpu_percent: list<array{t: string, avg: float, max: float}>, memory_bytes: list<array{t: string, avg: int, max: int}>, restarts: list<array{t: string, count: int}>}>
                 */
                'per_replica' => $this->when($data->perReplica !== null, fn (): array => array_map(fn (array $replica): array => [
                    'replica' => $replica['replica'],
                    'cpu_percent' => array_map(fn (array $point): array => [...$point, 't' => $this->time($point['t'])], $replica['cpu_percent']),
                    'memory_bytes' => array_map(fn (array $point): array => [...$point, 't' => $this->time($point['t'])], $replica['memory_bytes']),
                    'restarts' => array_map(fn (array $point): array => [...$point, 't' => $this->time($point['t'])], $replica['restarts']),
                ], $data->perReplica ?? [])),
            ],
        ];
    }

    private function time(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->toIso8601String();
    }
}
