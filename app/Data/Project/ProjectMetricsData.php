<?php

declare(strict_types=1);

namespace App\Data\Project;

use App\Enums\ProjectMetricsRange;

/**
 * Metric series of one project over a range, summed across its replicas.
 * Every series has a point for each step that has data, oldest first, and
 * none for a step without data.
 */
final readonly class ProjectMetricsData
{
    /**
     * @param  list<array{t: int, avg: float, max: float}>  $cpuPercent
     * @param  list<array{t: int, avg: int, max: int}>  $memoryBytes
     * @param  list<array{t: int, count: int}>  $replicas
     * @param  list<array{t: int, count: int}>  $restarts
     * @param  list<array{replica: int, cpu_percent: list<array{t: int, avg: float, max: float}>, memory_bytes: list<array{t: int, avg: int, max: int}>, restarts: list<array{t: int, count: int}>}>|null  $perReplica  Null unless asked for.
     */
    public function __construct(
        public ProjectMetricsRange $range,
        public int $stepSeconds,
        public array $cpuPercent,
        public array $memoryBytes,
        public ?int $memoryLimitBytes,
        public array $replicas,
        public array $restarts,
        public ?array $perReplica,
    ) {}
}
