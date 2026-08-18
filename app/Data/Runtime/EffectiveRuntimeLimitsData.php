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
     * @return array{memory_mb: int, cpu_millis: int, pids_limit: int}
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
     * @return array<string, mixed>
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
