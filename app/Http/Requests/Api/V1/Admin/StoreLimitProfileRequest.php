<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\LimitProfileData;
use App\Http\Requests\Concerns\RequiresAdminReason;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Rules\RegionSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLimitProfileRequest extends FormRequest
{
    use RequiresAdminReason;

    public const FIELDS = ['name', 'max_projects', 'max_active_containers', 'max_active_deployments', 'max_memory_mb', 'max_cpu_millis', 'max_builds_per_hour', 'idle_sleep_minutes', 'max_replicas', 'default_region', 'max_databases', 'max_database_storage_mb', 'max_buckets', 'max_bucket_storage_mb', 'max_worker_containers', 'max_schedules', 'min_schedule_interval_minutes', 'max_concurrent_job_runs'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->reasonRules(),
            'name' => ['required', 'string', 'max:100', Rule::unique(LimitProfile::class, 'name')],
            'max_projects' => ['required', 'integer', 'between:1,1000'],
            /** Projects that serve or are being deployed at once. */
            'max_active_containers' => ['required', 'integer', 'between:1,1000'],
            'max_active_deployments' => ['required', 'integer', 'between:1,100'],
            /** Per project, in MB. */
            'max_memory_mb' => ['required', 'integer', 'between:64,65536'],
            /** Per project, in thousandths of a CPU. */
            'max_cpu_millis' => ['required', 'integer', 'between:100,64000'],
            /** Deployments that build an image a workspace may create in the rolling hour; redeploys that reuse the live image do not count. Null follows SAKALA_PROJECT_MAX_BUILDS_PER_HOUR (default 20). */
            'max_builds_per_hour' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
            /**
             * Minutes without a request before a project sleeps, up to a week. `0` never puts the workspace's
             * projects to sleep; `null` (the default) follows `SAKALA_PROJECT_IDLE_SLEEP_MINUTES`.
             */
            'idle_sleep_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_IDLE_SLEEP_MINUTES],
            /**
             * Most replicas per project, 1 to 10. `null` (the default) allows one, so replicas stay off. All replicas of a
             * project run on one node, which may cap them lower.
             */
            'max_replicas' => ['sometimes', 'nullable', 'integer', 'between:'.Project::DEFAULT_REPLICAS.','.LimitProfile::MAX_REPLICAS_CEILING],
            /**
             * Region the workspace's projects run in unless a project sets its own, for example `jkt`. `null` (the
             * default) places them in any region. Lowercase letters, digits, and single inner hyphens, up to 32 characters.
             */
            'default_region' => ['sometimes', 'nullable', 'string', 'max:'.RegionSlug::MAX_LENGTH, new RegionSlug],
            /**
             * Managed databases the workspace may hold. `null` (the default) follows `SAKALA_DATABASE_DEFAULT_MAX_COUNT`;
             * `0` allows none.
             */
            'max_databases' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_DATABASES_CEILING],
            /**
             * Total size of the workspace's managed databases, in MB. `null` (the default) follows
             * `SAKALA_DATABASE_DEFAULT_MAX_STORAGE_MB`; `0` allows none, so every database of the workspace is locked.
             */
            'max_database_storage_mb' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_DATABASE_STORAGE_MB_CEILING],
            /**
             * Managed buckets the workspace may hold. `null` (the default) follows `SAKALA_BUCKET_DEFAULT_MAX_COUNT`;
             * `0` allows none.
             */
            'max_buckets' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_BUCKETS_CEILING],
            /**
             * MB the quotas of the workspace's buckets may reserve together. `null` (the default) follows
             * `SAKALA_BUCKET_DEFAULT_MAX_STORAGE_MB`; `0` refuses new buckets and quota increases. Lowering it never
             * shrinks an existing bucket.
             */
            'max_bucket_storage_mb' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_BUCKET_STORAGE_MB_CEILING],
            /**
             * Worker containers the workspace may run at once (ADR-022). They also count against
             * `max_active_containers`. `null` (the default) follows `SAKALA_WORKER_DEFAULT_MAX_CONTAINERS`; `0` allows none.
             */
            'max_worker_containers' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_WORKER_CONTAINERS_CEILING],
            /**
             * Enabled schedules the workspace may hold. `null` (the default) follows `SAKALA_SCHEDULE_DEFAULT_MAX_COUNT`;
             * `0` allows none.
             */
            'max_schedules' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_SCHEDULES_CEILING],
            /**
             * Shortest gap between two slots of a schedule, in minutes. `null` (the default) follows
             * `SAKALA_SCHEDULE_DEFAULT_MIN_INTERVAL_MINUTES`.
             */
            'min_schedule_interval_minutes' => ['sometimes', 'nullable', 'integer', 'between:'.LimitProfile::MIN_SCHEDULE_INTERVAL_FLOOR.','.LimitProfile::MIN_SCHEDULE_INTERVAL_CEILING],
            /**
             * Scheduled runs the workspace runs at once; a due run beyond that waits. `null` (the default) follows
             * `SAKALA_SCHEDULE_DEFAULT_MAX_CONCURRENT_RUNS`; `0` runs none.
             */
            'max_concurrent_job_runs' => ['sometimes', 'nullable', 'integer', 'between:0,'.LimitProfile::MAX_CONCURRENT_JOB_RUNS_CEILING],
        ];
    }

    public function toData(): LimitProfileData
    {
        /** @var array<string, int|string|null> $attributes */
        $attributes = $this->safe()->only(self::FIELDS);

        return new LimitProfileData($attributes, $this->reason());
    }
}
