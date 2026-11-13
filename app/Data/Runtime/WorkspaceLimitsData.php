<?php

declare(strict_types=1);

namespace App\Data\Runtime;

use App\Models\LimitProfile;

/**
 * The limits a workspace runs under: its limit profile, or the default for its
 * type when it has none.
 */
final readonly class WorkspaceLimitsData
{
    public function __construct(
        public int $max_projects,
        public int $max_active_containers,
        public int $max_active_deployments,
        public int $max_memory_mb,
        public int $max_cpu_millis,
        public ?int $max_builds_per_hour,
        public ?LimitProfile $profile = null,
        /** Effective minutes without a request before a project sleeps; 0 = never. */
        public int $idle_sleep_minutes = 0,
    ) {}

    public static function fromProfile(LimitProfile $profile, int $idleSleepMinutes): self
    {
        return new self(
            max_projects: $profile->max_projects,
            max_active_containers: $profile->max_active_containers,
            max_active_deployments: $profile->max_active_deployments,
            max_memory_mb: $profile->max_memory_mb,
            max_cpu_millis: $profile->max_cpu_millis,
            max_builds_per_hour: $profile->max_builds_per_hour,
            profile: $profile,
            idle_sleep_minutes: $idleSleepMinutes,
        );
    }
}
