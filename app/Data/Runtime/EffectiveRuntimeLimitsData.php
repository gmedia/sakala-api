<?php

declare(strict_types=1);

namespace App\Data\Runtime;

final readonly class EffectiveRuntimeLimitsData
{
    public function __construct(
        public int $memory_mb,
        public int $cpu_millis,
        public int $pids_limit,
        public RuntimeTimeoutLimitsData $timeouts,
        public LogBoundsData $log_bounds,
    ) {}

    /**
     * @return array{
     *     memory_mb: int,
     *     cpu_millis: int,
     *     pids_limit: int
     * }
     */
    public function toResourcesArray(): array
    {
        return [
            'memory_mb' => $this->memory_mb,
            'cpu_millis' => $this->cpu_millis,
            'pids_limit' => $this->pids_limit,
        ];
    }

    /**
     * @return array{
     *     resources: array{
     *         memory_mb: int,
     *         cpu_millis: int,
     *         pids_limit: int
     *     },
     *     timeouts: array{
     *         build_timeout_seconds: int,
     *         start_timeout_seconds: int,
     *         command_timeout_seconds: int
     *     },
     *     log_bounds: array{
     *         max_line_length: int,
     *         max_batch_lines: int,
     *         max_total_bytes: int
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'resources' => $this->toResourcesArray(),
            'timeouts' => $this->timeouts->toArray(),
            'log_bounds' => $this->log_bounds->toArray(),
        ];
    }
}
