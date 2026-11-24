<?php

declare(strict_types=1);

namespace App\Data\Runtime;

/**
 * The effective worker and schedule limits of a workspace (ADR-022), from
 * its limit profile or the global defaults (PilotRuntimeLimitService).
 */
final readonly class ScheduleLimitsData
{
    public function __construct(
        public int $maxWorkerContainers,
        public int $maxSchedules,
        public int $minScheduleIntervalMinutes,
        public int $maxConcurrentJobRuns,
    ) {}
}
