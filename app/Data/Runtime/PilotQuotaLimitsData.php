<?php

declare(strict_types=1);

namespace App\Data\Runtime;

/**
 * Quotas and usage of one workspace. The `_per_user` names predate workspaces
 * and are kept for the console; they mean "per workspace".
 */
final readonly class PilotQuotaLimitsData
{
    public function __construct(
        public string $workspace_id,
        public int $max_projects_per_user,
        public int $max_active_deployments_per_user,
        public int $max_active_deployments_per_project,
        public int $current_projects_count,
        public int $current_active_deployments_count,
        public int $max_active_containers,
        public int $current_active_containers_count,
        public WorkspaceLimitsData $limits,
        /** Managed databases that count towards `limits->max_databases`. */
        public int $current_databases_count = 0,
        /** Reported size of the workspace's managed databases, in bytes. */
        public int $current_database_storage_bytes = 0,
        /** Managed buckets that count towards `limits->max_buckets`. */
        public int $current_buckets_count = 0,
        /** MB the workspace's bucket quotas reserve (allocated, not used). */
        public int $current_bucket_storage_mb = 0,
        /** Usage the storage nodes last reported for the workspace's buckets, in bytes. */
        public int $current_bucket_usage_bytes = 0,
        /** Worker containers of the projects that serve or are being deployed (ADR-022). */
        public int $current_worker_containers_count = 0,
        /** Enabled schedules that count towards `limits->max_schedules`. */
        public int $current_schedules_count = 0,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'max_projects_per_user' => $this->max_projects_per_user,
            'max_active_deployments_per_user' => $this->max_active_deployments_per_user,
            'max_active_deployments_per_project' => $this->max_active_deployments_per_project,
            'current_projects_count' => $this->current_projects_count,
            'current_active_deployments_count' => $this->current_active_deployments_count,
            'max_active_containers' => $this->max_active_containers,
            'current_active_containers_count' => $this->current_active_containers_count,
        ];
    }
}
