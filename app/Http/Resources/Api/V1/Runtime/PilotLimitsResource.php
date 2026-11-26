<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Runtime;

use App\Data\Runtime\PilotQuotaLimitsData;
use App\Models\ManagedValkey;
use App\Services\CustomDomain\CustomDomainPolicy;
use App\Services\Schedule\SchedulePolicy;
use App\Support\Runtime\RuntimeFeatures;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PilotQuotaLimitsData
 *
 * @property PilotQuotaLimitsData $resource
 */
final class PilotLimitsResource extends JsonResource
{
    /** Whether the installation offers managed databases (AgentNodeSchedulerService::databasesAvailable()). */
    public bool $databasesAvailable = false;

    /** Whether the installation offers managed buckets (AgentNodeSchedulerService::bucketsAvailable()). */
    public bool $bucketsAvailable = false;

    /** Quota of a bucket created without `quota_mb` (BucketQuotaPolicy::defaultQuotaMb()). */
    public int $bucketDefaultQuotaMb = 0;

    /** Whether the installation offers managed Valkey stores (AgentNodeSchedulerService::valkeysAvailable()). */
    public bool $valkeysAvailable = false;

    public function withValkeysAvailable(bool $available): static
    {
        $this->valkeysAvailable = $available;

        return $this;
    }

    public function withDatabasesAvailable(bool $available): static
    {
        $this->databasesAvailable = $available;

        return $this;
    }

    public function withBuckets(bool $available, int $defaultQuotaMb): static
    {
        $this->bucketsAvailable = $available;
        $this->bucketDefaultQuotaMb = $defaultQuotaMb;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $limits = $this->resource->limits;

        return [
            /** The workspace these quotas and maximums belong to. */
            'workspace_id' => $this->resource->workspace_id,
            /**
             * The limit profile attached to the workspace, or null when it runs under the default for its type.
             */
            'limit_profile' => $limits->profile === null ? null : [
                'id' => $limits->profile->id,
                'name' => $limits->profile->name,
            ],
            'quotas' => [
                'max_projects_per_user' => $this->resource->max_projects_per_user,
                'max_active_deployments_per_user' => $this->resource->max_active_deployments_per_user,
                'max_active_deployments_per_project' => $this->resource->max_active_deployments_per_project,
                'current_projects_count' => $this->resource->current_projects_count,
                'current_active_deployments_count' => $this->resource->current_active_deployments_count,
                'max_active_containers' => $this->resource->max_active_containers,
                /** Replicas of the projects that serve or are being deployed; sleeping projects do not count. */
                'current_active_containers_count' => $this->resource->current_active_containers_count,
                /** Most replicas per project, from the workspace's limit profile; 1 without one. */
                'max_replicas' => $limits->max_replicas,
                /**
                 * Deployments the workspace may create per rolling hour, from its limit profile or the global
                 * setting; every deployment builds. `null` means no limit.
                 */
                'max_builds_per_hour' => $limits->max_builds_per_hour,
                /** Managed databases the workspace may hold, from its limit profile or the global setting; `0` allows none. */
                'max_databases' => $limits->max_databases,
                /** Managed databases that count towards `max_databases`: every live one except those whose creation failed. */
                'current_databases_count' => $this->resource->current_databases_count,
                /**
                 * Total size the workspace's managed databases may reach, in MB. At 80 % its owners and members are
                 * notified; at 100 % every database of the workspace is locked. `0` allows none.
                 */
                'max_database_storage_mb' => $limits->max_database_storage_mb,
                /** Size of the workspace's managed databases from the database node's last report, in bytes. */
                'current_database_storage_bytes' => $this->resource->current_database_storage_bytes,
                /** Managed buckets the workspace may hold, from its limit profile or the global setting; `0` allows none. */
                'max_buckets' => $limits->max_buckets,
                /** Managed buckets that count towards `max_buckets`: every live one except those whose creation failed. */
                'current_buckets_count' => $this->resource->current_buckets_count,
                /**
                 * MB the quotas of the workspace's buckets may reserve together, from its limit profile or the global
                 * setting. `0` refuses new buckets and quota increases; existing buckets keep their quotas.
                 */
                'max_bucket_storage_mb' => $limits->max_bucket_storage_mb,
                /**
                 * MB the workspace's buckets reserve now: the sum of their quotas, a pending increase included.
                 * Allocated, not used; see `current_bucket_usage_bytes`.
                 */
                'current_bucket_storage_mb' => $this->resource->current_bucket_storage_mb,
                /** Usage of the workspace's buckets from the storage nodes' last reports, in bytes; a bucket never measured counts 0. */
                'current_bucket_usage_bytes' => $this->resource->current_bucket_usage_bytes,
                /**
                 * Worker containers the workspace may run at once, from its limit profile or the global setting
                 * (ADR-022). They also count towards `max_active_containers`. `0` allows none.
                 */
                'max_worker_containers' => $limits->max_worker_containers,
                /** Worker containers of the workspace's projects that serve or are being deployed. */
                'current_worker_containers_count' => $this->resource->current_worker_containers_count,
                /** Enabled schedules the workspace may hold, from its limit profile or the global setting; `0` allows none. */
                'max_schedules' => $limits->max_schedules,
                /** Enabled schedules that count towards `max_schedules`. */
                'current_schedules_count' => $this->resource->current_schedules_count,
                /** Shortest gap between two slots of a schedule, in minutes, from its limit profile or the global setting. */
                'min_schedule_interval_minutes' => $limits->min_schedule_interval_minutes,
                /** Scheduled runs the workspace runs at once; a due run beyond that waits. `0` runs none. */
                'max_concurrent_job_runs' => $limits->max_concurrent_job_runs,
                /** Managed Valkey stores the workspace may hold, from its limit profile or the global setting (ADR-023); `0` allows none. */
                'max_valkeys' => $limits->max_valkeys,
                /** Managed Valkey stores that count towards `max_valkeys`: every live one except those whose creation failed. */
                'current_valkeys_count' => $this->resource->current_valkeys_count,
                /**
                 * MB of `maxmemory` the workspace's Valkey stores may reserve together, from its limit profile or the
                 * global setting. `0` refuses new stores and growth; existing stores keep their size.
                 */
                'max_valkey_memory_mb' => $limits->max_valkey_memory_mb,
                /** MB the workspace's Valkey stores reserve now: the sum of their sizes, a pending growth included. */
                'current_valkey_memory_mb' => $this->resource->current_valkey_memory_mb,
                /**
                 * Custom domains the workspace's projects may hold together, from its limit profile or the global
                 * setting (ADR-024); `0` allows none.
                 */
                'max_custom_domains' => $limits->max_custom_domains,
                /** Custom domain claims that count towards `max_custom_domains`: pending, verified, failed, and disabled ones. */
                'current_custom_domains_count' => $this->resource->current_custom_domains_count,
            ],
            'runtime_defaults' => [
                /** @var int */
                'memory_mb' => min((int) config('sakala.pilot_limits.resources.default_memory_mb', 256), $limits->max_memory_mb),
                /** @var int */
                'cpu_millis' => min((int) config('sakala.pilot_limits.resources.default_cpu_millis', 500), $limits->max_cpu_millis),
                'pids_limit' => (int) config('sakala.pilot_limits.resources.default_pids_limit', 128),
            ],
            'runtime_maximums' => [
                'memory_mb' => $limits->max_memory_mb,
                'cpu_millis' => $limits->max_cpu_millis,
                'pids_limit' => (int) config('sakala.pilot_limits.resources.max_pids_limit', 256),
            ],
            'timeouts' => [
                'build_timeout_seconds' => (int) config('sakala.pilot_limits.timeouts.build_timeout_seconds', 600),
                'start_timeout_seconds' => (int) config('sakala.pilot_limits.timeouts.start_timeout_seconds', 120),
                'command_timeout_seconds' => (int) config('sakala.pilot_limits.timeouts.command_timeout_seconds', 900),
            ],
            'log_bounds' => [
                'max_line_length' => (int) config('sakala.pilot_limits.log_bounds.max_line_length', 4096),
                'max_batch_lines' => (int) config('sakala.pilot_limits.log_bounds.max_batch_lines', 500),
                'max_total_bytes' => (int) config('sakala.pilot_limits.log_bounds.max_total_bytes', 10 * 1024 * 1024),
                'max_request_bytes' => (int) config('sakala.pilot_limits.log_bounds.max_request_bytes', 1024 * 1024),
            ],
            'deleted_project_retention_days' => (int) config('sakala.project.deleted_retention_days', 30),
            /**
             * Minutes without a request before a running project of this workspace is put to sleep, from its
             * limit profile or the global setting. `0` means its projects never sleep.
             */
            'project_idle_sleep_minutes' => $limits->idle_sleep_minutes,
            /**
             * Managed databases on this installation. `available` is true while at least one online database node
             * may receive new databases and its PostgreSQL instance last reported that it answers. False on an
             * installation without a database server, and while every database node is offline or its instance
             * is down: creating one would wait or fail. Existing databases stay listed either way.
             *
             * @var array{available: bool}
             */
            'databases' => [
                'available' => $this->databasesAvailable,
            ],
            /**
             * Managed S3 buckets on this installation (ADR-021). `available` is true while at least one online node
             * with the `managed-s3` capability may receive new buckets, has reported its object storage, and did not
             * report it down. `default_quota_mb` is the quota of a bucket created without `quota_mb`, before it is
             * capped at what the workspace has left. Absent on an API without buckets.
             */
            'buckets' => [
                'available' => $this->bucketsAvailable,
                // Typed by hand: Scramble would document the property's initial 0 as a constant.
                /**
                 * Quota of a bucket created without `quota_mb`, in MB: `SAKALA_BUCKET_DEFAULT_QUOTA_MB`, or 256 when
                 * unset, kept between 1 and 1048576. Never null.
                 *
                 * @var int
                 */
                'default_quota_mb' => $this->bucketDefaultQuotaMb,
            ],
            /**
             * Worker processes on this installation (ADR-022). `enabled` follows `SAKALA_WORKERS_ENABLED`; while it
             * is false the process endpoints answer 404 and deployments carry no workers.
             */
            'workers' => [
                'enabled' => RuntimeFeatures::workersEnabled(),
            ],
            /**
             * Scheduled jobs on this installation (ADR-022). `enabled` follows `SAKALA_SCHEDULES_ENABLED`; while it
             * is false the schedule endpoints answer 404 and nothing is dispatched. `default_timezone` is the
             * timezone of a schedule created without one, `max_timeout_seconds` the longest run timeout, and
             * `start_deadline_minutes` how late a slot may still start.
             *
             * @var array{enabled: bool, default_timezone: string, default_timeout_seconds: int, max_timeout_seconds: int, start_deadline_minutes: int}
             */
            'schedules' => [
                'enabled' => RuntimeFeatures::schedulesEnabled(),
                'default_timezone' => SchedulePolicy::DEFAULT_TIMEZONE,
                'default_timeout_seconds' => SchedulePolicy::DEFAULT_TIMEOUT_SECONDS,
                'max_timeout_seconds' => SchedulePolicy::MAX_TIMEOUT_SECONDS,
                'start_deadline_minutes' => app(SchedulePolicy::class)->startDeadlineMinutes(),
            ],
            /**
             * Managed Valkey stores on this installation (ADR-023). `enabled` follows `SAKALA_VALKEY_ENABLED`; while it
             * is false the Valkey endpoints answer 404 and deployments carry no store. `available` is true while it
             * is on and at least one online node with the `managed-valkey` capability may receive new stores and did
             * not report its role down. `sizes_mb` lists the sizes a store may have, `default_size_mb` the one used
             * without `maxmemory_mb`.
             *
             * @var array{enabled: bool, available: bool, sizes_mb: list<int>, default_size_mb: int}
             */
            'valkeys' => [
                'enabled' => RuntimeFeatures::valkeyEnabled(),
                'available' => $this->valkeysAvailable,
                'sizes_mb' => ManagedValkey::SIZES_MB,
                'default_size_mb' => ManagedValkey::DEFAULT_SIZE_MB,
            ],
            /**
             * Custom domains for projects on this installation (ADR-024). `enabled` follows
             * `SAKALA_CUSTOM_DOMAINS_ENABLED`; while it is false the domain endpoints answer 404. `target` is the name
             * a domain points at with a CNAME (or flattening, ALIAS, ANAME for an apex); never an IP address.
             *
             * @var array{enabled: bool, target: string}
             */
            'custom_domains' => [
                'enabled' => RuntimeFeatures::customDomainsEnabled(),
                'target' => app(CustomDomainPolicy::class)->target(),
            ],
        ];
    }
}
