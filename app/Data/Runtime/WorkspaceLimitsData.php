<?php

declare(strict_types=1);

namespace App\Data\Runtime;

use App\Models\LimitProfile;
use App\Models\Project;

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
        /** Effective deployments per rolling hour; null = no limit. */
        public ?int $max_builds_per_hour,
        public ?LimitProfile $profile = null,
        /** Effective minutes without a request before a project sleeps; 0 = never. */
        public int $idle_sleep_minutes = 0,
        /** Effective most replicas per project. */
        public int $max_replicas = Project::DEFAULT_REPLICAS,
        /** Effective managed databases the workspace may hold; 0 = none. */
        public int $max_databases = 0,
        /** Effective total managed database storage in MB; 0 = none. */
        public int $max_database_storage_mb = 0,
        /** Effective managed buckets the workspace may hold; 0 = none. */
        public int $max_buckets = 0,
        /** Effective MB the workspace's bucket quotas may reserve together; 0 = no new allocation. */
        public int $max_bucket_storage_mb = 0,
    ) {}

    public static function fromProfile(LimitProfile $profile, int $idleSleepMinutes, int $maxReplicas, ?int $maxBuildsPerHour, int $maxDatabases, int $maxDatabaseStorageMb, int $maxBuckets, int $maxBucketStorageMb): self
    {
        return new self(
            max_projects: $profile->max_projects,
            max_active_containers: $profile->max_active_containers,
            max_active_deployments: $profile->max_active_deployments,
            max_memory_mb: $profile->max_memory_mb,
            max_cpu_millis: $profile->max_cpu_millis,
            max_builds_per_hour: $maxBuildsPerHour,
            profile: $profile,
            idle_sleep_minutes: $idleSleepMinutes,
            max_replicas: $maxReplicas,
            max_databases: $maxDatabases,
            max_database_storage_mb: $maxDatabaseStorageMb,
            max_buckets: $maxBuckets,
            max_bucket_storage_mb: $maxBucketStorageMb,
        );
    }
}
