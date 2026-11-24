<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Data\Agent\AgentHeartbeatData;
use App\Data\Agent\BucketSizesData;
use App\Data\Agent\CrashedWorkloadsData;
use App\Data\Agent\DatabaseSizesData;
use App\Data\Agent\WorkloadMetricsData;
use App\Data\Agent\WorkloadReplicasData;
use App\Data\Agent\WorkloadTrafficData;
use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use App\Models\ManagedBucket;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\ProjectProcess;
use App\Rules\EdgeAddress;
use App\Rules\NetworkHost;
use App\Rules\ObjectStorageEndpoint;
use App\Rules\RegionSlug;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AgentHeartbeatRequest extends FormRequest
{
    public const MAX_TRAFFIC_ITEMS = 500;

    /** Same bound as traffic: one item per running workload at most. */
    public const MAX_REPLICA_ITEMS = 500;

    /**
     * Bound of one reported replica count. Real counts stay far below
     * (LimitProfile::MAX_REPLICAS_CEILING); this only keeps the smallint
     * columns safe without rejecting a heartbeat over a near miss.
     */
    public const MAX_REPORTED_REPLICAS = 1000;

    /** Same bound as traffic: one sample per running container is the norm. */
    public const MAX_METRIC_ITEMS = 500;

    /** Key of the metric samples under `metadata.workloads`. */
    public const METRICS_KEY = 'metrics';

    /**
     * `cpu_percent` is a percent of one core, so 100 per core: this allows
     * 1024 cores, far beyond any node, and keeps absurd values out.
     */
    public const MAX_REPORTED_CPU_PERCENT = 102400;

    /**
     * 1 PiB. Real containers stay far below; the bound keeps the summed
     * memory of one replica over an hour inside a signed 64-bit integer for
     * up to 8192 samples, more than two a second.
     */
    public const MAX_REPORTED_MEMORY_BYTES = 1_125_899_906_842_624;

    /** Key of the database size list under `metadata`. */
    public const DATABASES_KEY = 'databases';

    /**
     * Databases one heartbeat may list. ADR-020 sizes an instance for a few
     * hundred tenant databases; the bound keeps one heartbeat cheap.
     */
    public const MAX_DATABASE_ITEMS = 1000;

    /** 1 PiB, like memory: far beyond any database, and inside a signed 64-bit integer when summed per workspace. */
    public const MAX_REPORTED_DATABASE_BYTES = 1_125_899_906_842_624;

    /** Key of the bucket usage list under `metadata` (ADR-021). */
    public const BUCKETS_KEY = 'buckets';

    /** Buckets one heartbeat may list; the agent truncates to this many and reports the full count. */
    public const MAX_BUCKET_ITEMS = 1000;

    /** 1 PiB, like a database: far beyond any bucket, and inside a signed 64-bit integer when summed per workspace. */
    public const MAX_REPORTED_BUCKET_BYTES = 1_125_899_906_842_624;

    /** Highest value the unsigned integer restart column holds on every database. */
    public const MAX_REPORTED_RESTART_COUNT = 2147483647;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(
                AgentNodeStatus::Ready->value,
                AgentNodeStatus::Busy->value,
                AgentNodeStatus::Degraded->value,
                AgentNodeStatus::Draining->value,
                AgentNodeStatus::Drained->value,
                AgentNodeStatus::Maintenance->value
            )],
            'hostname' => ['required', 'string', 'max:255'],
            'runtime_network' => ['required', 'string', 'max:120'],
            'capabilities' => ['required', 'array', 'max:50'],
            'capabilities.*' => ['required', 'string', 'max:255'],

            'metadata' => ['required', 'array'],
            'metadata.version' => ['required', 'string', 'max:50'],
            'metadata.protocol_version' => ['required', 'integer', 'min:1'],
            'metadata.runtime_driver' => ['required', 'string', 'max:50'],
            'metadata.lifecycle_state' => ['required', 'string', 'max:50'],
            'metadata.uptime_seconds' => ['present', 'nullable', 'integer', 'min:0'],

            // Sent since agent v0.2.0 so the API can tell when detail arrays
            // were truncated to 50 items; v0.1.0 omits it. Optional, but
            // complete when present.
            'metadata.detail_counts' => ['sometimes', 'array'],
            'metadata.detail_counts.unhealthy_details' => ['required_with:metadata.detail_counts', 'integer', 'min:0'],
            'metadata.detail_counts.recovered_workloads' => ['required_with:metadata.detail_counts', 'integer', 'min:0'],
            'metadata.detail_counts.orphans' => ['required_with:metadata.detail_counts', 'integer', 'min:0'],
            'metadata.detail_counts.stale_routes' => ['required_with:metadata.detail_counts', 'integer', 'min:0'],
            'metadata.detail_counts.stale_images' => ['required_with:metadata.detail_counts', 'integer', 'min:0'],
            'metadata.detail_counts.compatibility_issues' => ['required_with:metadata.detail_counts', 'integer', 'min:0'],
            // Additive within protocol revision 4: v0.2.0 sends detail_counts
            // without this key, so it is tied to the list, not to detail_counts.
            'metadata.detail_counts.crashed_details' => ['required_with:metadata.workloads.crashed_details', 'integer', 'min:0'],
            // Additive within protocol revision 4, sent with `traffic` (idle sleep).
            'metadata.detail_counts.traffic' => ['sometimes', 'integer', 'min:0'],
            // Additive within protocol revision 4, sent with `replicas`.
            'metadata.detail_counts.replicas' => ['sometimes', 'integer', 'min:0'],
            // Additive within protocol revision 4, sent with `metrics`.
            'metadata.detail_counts.'.self::METRICS_KEY => ['sometimes', 'integer', 'min:0'],
            // Additive within protocol revision 4, sent with `databases`.
            'metadata.detail_counts.'.self::DATABASES_KEY => ['sometimes', 'integer', 'min:0'],
            // Additive within protocol revision 4, sent with `buckets`.
            'metadata.detail_counts.'.self::BUCKETS_KEY => ['sometimes', 'integer', 'min:0'],

            'metadata.resources' => ['required', 'array'],
            'metadata.resources.cpu_total' => ['present', 'nullable', 'integer', 'min:1'],
            'metadata.resources.cpu_load_1m' => ['present', 'nullable', 'numeric', 'min:0'],
            'metadata.resources.memory_total_bytes' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.resources.memory_available_bytes' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.resources.disk_total_bytes' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.resources.disk_available_bytes' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.resources.workspace_used_bytes' => ['present', 'nullable', 'integer', 'min:0'],

            'metadata.workloads' => ['required', 'array'],
            'metadata.workloads.active' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.workloads.starting' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.workloads.unhealthy' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.workloads.stopped' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.workloads.unhealthy_details' => ['present', 'array', 'max:50'],
            // Optional: agents without crash reporting omit the list, and only
            // a present list may change runtime state.
            'metadata.workloads.crashed_details' => ['sometimes', 'array', 'max:50'],
            'metadata.workloads.crashed_details.*' => ['required', 'array'],
            'metadata.workloads.crashed_details.*.project_id' => ['required', 'uuid'],
            'metadata.workloads.crashed_details.*.deployment_id' => ['required', 'uuid'],
            'metadata.workloads.crashed_details.*.exit_code' => ['present', 'nullable', 'integer'],
            'metadata.workloads.crashed_details.*.oom_killed' => ['required', 'boolean:strict'],
            'metadata.workloads.crashed_details.*.finished_at' => ['present', 'nullable', 'date'],
            // Additive within protocol revision 4 (ADR-022): only on worker
            // containers. Absent means the web process. A crashing worker
            // never marks the web process crashed.
            'metadata.workloads.crashed_details.*.process' => ['sometimes', 'nullable', 'string', 'max:'.ProjectProcess::MAX_NAME_LENGTH, 'regex:'.ProjectProcess::NAME_PATTERN],
            'metadata.workloads.crashed_details.*.restart_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'metadata.workloads.crashed_details.*.running' => ['sometimes', 'nullable', 'boolean:strict'],
            // Optional, from nodes with the `idle-sleep` capability. The agent
            // caps it at its own max active containers, not at 50; the bound
            // here only keeps one heartbeat cheap. Like crashed_details, a
            // malformed item rejects the whole heartbeat.
            'metadata.workloads.traffic' => ['sometimes', 'array', 'max:'.self::MAX_TRAFFIC_ITEMS],
            'metadata.workloads.traffic.*' => ['required', 'array'],
            'metadata.workloads.traffic.*.project_id' => ['required', 'uuid'],
            'metadata.workloads.traffic.*.deployment_id' => ['required', 'uuid'],
            'metadata.workloads.traffic.*.last_request_at' => ['required', 'date'],
            // Optional, from nodes with the `replicas` capability: one item
            // per deployment that runs replicas. Bounded and strict like
            // traffic. Partial readiness is not a crash; only crashed_details
            // changes runtime state.
            // Sent by agents with the `replicas` capability on every
            // heartbeat; null when the node's driver cannot run replicas.
            'metadata.workloads.'.AgentNode::MAXIMUM_REPLICAS_METADATA_KEY => ['sometimes', 'nullable', 'integer', 'between:'.Project::DEFAULT_REPLICAS.','.AgentNode::MAXIMUM_REPLICAS_REPORT_CEILING],
            // Optional and additive within protocol revision 4: the most
            // containers the node runs at once (SAKALA_MAX_ACTIVE_CONTAINERS),
            // for capacity-aware placement.
            'metadata.workloads.'.AgentNode::MAXIMUM_ACTIVE_WORKLOADS_METADATA_KEY => ['sometimes', 'nullable', 'integer', 'min:0'],
            'metadata.workloads.replicas' => ['sometimes', 'array', 'max:'.self::MAX_REPLICA_ITEMS],
            'metadata.workloads.replicas.*' => ['required', 'array'],
            'metadata.workloads.replicas.*.project_id' => ['required', 'uuid'],
            'metadata.workloads.replicas.*.deployment_id' => ['required', 'uuid'],
            'metadata.workloads.replicas.*.desired' => ['required', 'integer', 'min:1', 'max:'.self::MAX_REPORTED_REPLICAS],
            'metadata.workloads.replicas.*.ready' => ['required', 'integer', 'min:0', 'max:'.self::MAX_REPORTED_REPLICAS],
            // Optional, from nodes with the `workload-metrics` capability: one
            // resource sample per replica. Bounded and strict like traffic; an
            // item for a project or deployment the node does not serve is
            // well-formed and only ignored (RecordWorkloadMetricsAction).
            'metadata.workloads.'.self::METRICS_KEY => ['sometimes', 'array', 'max:'.self::MAX_METRIC_ITEMS],
            'metadata.workloads.'.self::METRICS_KEY.'.*' => ['required', 'array'],
            'metadata.workloads.'.self::METRICS_KEY.'.*.project_id' => ['required', 'uuid'],
            'metadata.workloads.'.self::METRICS_KEY.'.*.deployment_id' => ['required', 'uuid'],
            'metadata.workloads.'.self::METRICS_KEY.'.*.replica' => ['required', 'integer', 'min:0', 'max:'.(self::MAX_REPORTED_REPLICAS - 1)],
            'metadata.workloads.'.self::METRICS_KEY.'.*.sampled_at' => ['required', 'date'],
            'metadata.workloads.'.self::METRICS_KEY.'.*.cpu_percent' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_REPORTED_CPU_PERCENT],
            'metadata.workloads.'.self::METRICS_KEY.'.*.memory_bytes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_REPORTED_MEMORY_BYTES],
            'metadata.workloads.'.self::METRICS_KEY.'.*.memory_limit_bytes' => ['present', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_REPORTED_MEMORY_BYTES],
            'metadata.workloads.'.self::METRICS_KEY.'.*.restart_count' => ['required', 'integer', 'min:0', 'max:'.self::MAX_REPORTED_RESTART_COUNT],
            // Additive within protocol revision 4 (ADR-022): only on worker
            // containers, whose samples are not project metrics.
            'metadata.workloads.'.self::METRICS_KEY.'.*.process' => ['sometimes', 'nullable', 'string', 'max:'.ProjectProcess::MAX_NAME_LENGTH, 'regex:'.ProjectProcess::NAME_PATTERN],

            // Optional and additive within protocol revision 4: where the node
            // is (SAKALA_AGENT_REGION) and where Sakala Edge reaches its
            // runtime router. An admin override on the node wins over both.
            // A malformed value rejects the heartbeat, like the lists above.
            'metadata.'.AgentNode::RUNTIME_METADATA_KEY => ['sometimes', 'array'],
            'metadata.'.AgentNode::RUNTIME_METADATA_KEY.'.'.AgentNode::REGION_METADATA_KEY => ['sometimes', 'nullable', 'string', 'max:'.RegionSlug::MAX_LENGTH, new RegionSlug],
            'metadata.'.AgentNode::RUNTIME_METADATA_KEY.'.'.AgentNode::EDGE_ADDRESS_METADATA_KEY => ['sometimes', 'nullable', 'string', 'max:'.EdgeAddress::MAX_LENGTH, new EdgeAddress],

            // Optional and additive within protocol revision 4, from nodes with
            // the `managed-postgres` capability (ADR-020): where attached
            // projects reach the PostgreSQL instance, and the size of every
            // database it hosts. Strict like the lists above.
            'metadata.'.AgentNode::DATABASE_METADATA_KEY => ['sometimes', 'array'],
            'metadata.'.AgentNode::DATABASE_METADATA_KEY.'.'.AgentNode::DATABASE_ENDPOINT_METADATA_KEY => ['sometimes', 'nullable', 'array'],
            'metadata.'.AgentNode::DATABASE_METADATA_KEY.'.'.AgentNode::DATABASE_ENDPOINT_METADATA_KEY.'.host' => ['required_with:metadata.'.AgentNode::DATABASE_METADATA_KEY.'.'.AgentNode::DATABASE_ENDPOINT_METADATA_KEY, 'string', 'max:'.NetworkHost::MAX_LENGTH, new NetworkHost],
            'metadata.'.AgentNode::DATABASE_METADATA_KEY.'.'.AgentNode::DATABASE_ENDPOINT_METADATA_KEY.'.port' => ['required_with:metadata.'.AgentNode::DATABASE_METADATA_KEY.'.'.AgentNode::DATABASE_ENDPOINT_METADATA_KEY, 'integer', 'between:1,'.AgentNode::MAX_PORT],
            // False while the agent cannot read the instance; the node then
            // reports `degraded` and gets no new database.
            'metadata.'.AgentNode::DATABASE_METADATA_KEY.'.'.AgentNode::DATABASE_AVAILABLE_METADATA_KEY => ['sometimes', 'boolean:strict'],
            // Only on a heartbeat right after a fresh size reading (at most
            // once a minute): absence means no new reading, never zero.
            'metadata.'.self::DATABASES_KEY => ['sometimes', 'array', 'max:'.self::MAX_DATABASE_ITEMS],
            'metadata.'.self::DATABASES_KEY.'.*' => ['required', 'array'],
            'metadata.'.self::DATABASES_KEY.'.*.name' => ['required', 'string', 'regex:'.ManagedDatabase::IDENTIFIER_PATTERN],
            'metadata.'.self::DATABASES_KEY.'.*.size_bytes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_REPORTED_DATABASE_BYTES],

            // Optional and additive within protocol revision 4, from nodes with
            // the `managed-s3` capability (ADR-021): the public S3 endpoint,
            // its signing region, the MB the node may allocate to bucket
            // quotas, and the usage of every managed bucket it hosts. Strict
            // like the database fields: a malformed value rejects the heartbeat.
            'metadata.'.AgentNode::OBJECT_STORAGE_METADATA_KEY => ['sometimes', 'array'],
            'metadata.'.AgentNode::OBJECT_STORAGE_METADATA_KEY.'.'.AgentNode::OBJECT_STORAGE_ENDPOINT_METADATA_KEY => ['required_with:metadata.'.AgentNode::OBJECT_STORAGE_METADATA_KEY, 'string', 'max:'.ObjectStorageEndpoint::MAX_LENGTH, new ObjectStorageEndpoint],
            'metadata.'.AgentNode::OBJECT_STORAGE_METADATA_KEY.'.'.AgentNode::OBJECT_STORAGE_REGION_METADATA_KEY => ['required_with:metadata.'.AgentNode::OBJECT_STORAGE_METADATA_KEY, 'string', 'max:'.RegionSlug::MAX_LENGTH, new RegionSlug],
            'metadata.'.AgentNode::OBJECT_STORAGE_METADATA_KEY.'.'.AgentNode::OBJECT_STORAGE_CAPACITY_METADATA_KEY => ['required_with:metadata.'.AgentNode::OBJECT_STORAGE_METADATA_KEY, 'integer', 'min:1'],
            // False while the agent cannot read the instance; the node then
            // reports `degraded` and gets no new bucket first.
            'metadata.'.AgentNode::OBJECT_STORAGE_METADATA_KEY.'.'.AgentNode::OBJECT_STORAGE_AVAILABLE_METADATA_KEY => ['sometimes', 'boolean:strict'],
            // Only on a heartbeat right after a fresh usage reading (at most
            // once a minute): absence means no new reading, never zero.
            'metadata.'.self::BUCKETS_KEY => ['sometimes', 'array', 'max:'.self::MAX_BUCKET_ITEMS],
            'metadata.'.self::BUCKETS_KEY.'.*' => ['required', 'array'],
            'metadata.'.self::BUCKETS_KEY.'.*.name' => ['required', 'string', 'regex:'.ManagedBucket::S3_NAME_PATTERN],
            'metadata.'.self::BUCKETS_KEY.'.*.size_bytes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_REPORTED_BUCKET_BYTES],

            'metadata.disk_pressure' => ['required', 'array'],
            'metadata.disk_pressure.state' => ['required', 'string', 'max:50'],
            'metadata.disk_pressure.minimum_workspace_free_bytes' => ['required', 'integer', 'min:0'],
            'metadata.disk_pressure.available_workspace_bytes' => ['present', 'nullable', 'integer', 'min:0'],

            'metadata.runtime_dependencies' => ['present', 'nullable', 'array'],
            'metadata.runtime_dependencies.git' => ['nullable', 'string', 'max:255'],
            'metadata.runtime_dependencies.docker' => ['nullable', 'string', 'max:255'],
            'metadata.runtime_dependencies.buildx' => ['nullable', 'string', 'max:255'],
            'metadata.runtime_dependencies.railpack' => ['nullable', 'string', 'max:255'],

            'metadata.execution' => ['required', 'array'],
            'metadata.execution.active_commands' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.execution.queued_local_commands' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.execution.capacity_waiting_commands' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.execution.active_builds' => ['present', 'nullable', 'integer', 'min:0'],
            'metadata.execution.maximum_concurrent_builds' => ['present', 'nullable', 'integer', 'min:0'],

            'metadata.startup_reconciliation' => ['required', 'array'],
            'metadata.startup_reconciliation.captured_at' => ['required', 'date'],
            'metadata.startup_reconciliation.inspected_containers' => ['required', 'integer', 'min:0'],
            'metadata.startup_reconciliation.cleaned_workspaces' => ['required', 'integer', 'min:0'],
            'metadata.startup_reconciliation.reattached_log_followers' => ['required', 'integer', 'min:0'],
            'metadata.startup_reconciliation.recovered_execution_records' => ['required', 'integer', 'min:0'],
            'metadata.startup_reconciliation.recovered_workloads' => ['present', 'array', 'max:50'],
            'metadata.startup_reconciliation.orphans' => ['present', 'array', 'max:50'],
            'metadata.startup_reconciliation.stale_routes' => ['present', 'array', 'max:50'],
            'metadata.startup_reconciliation.stale_images' => ['present', 'array', 'max:50'],
            // The v0.1.0 documented payload omits this list even though the
            // binary sends it; accept both.
            'metadata.startup_reconciliation.compatibility_issues' => ['sometimes', 'array', 'max:50'],
            // Stale route items: `deployment_id` is sent since v0.2.0 and is
            // null for a legacy route generation; v0.1.0 items omit it.
            'metadata.startup_reconciliation.stale_routes.*.path' => ['required', 'string', 'max:4096'],
            'metadata.startup_reconciliation.stale_routes.*.project_id' => ['nullable', 'uuid'],
            'metadata.startup_reconciliation.stale_routes.*.deployment_id' => ['nullable', 'uuid'],

            'sent_at' => ['required', 'date'],
        ];
    }

    public function toData(): AgentHeartbeatData
    {
        return new AgentHeartbeatData(
            hostname: $this->validated('hostname'),
            runtimeNetwork: $this->validated('runtime_network'),
            capabilities: $this->validated('capabilities'),
            metadata: $this->persistedMetadata(),
            status: AgentNodeStatus::from($this->validated('status')),
            protocolVersion: (int) $this->validated('metadata.protocol_version'),
            sentAt: CarbonImmutable::parse($this->validated('sent_at')),
            crashedWorkloads: $this->crashedWorkloads(),
            workloadTraffic: $this->workloadTraffic(),
            workloadReplicas: $this->workloadReplicas(),
            workloadMetrics: $this->workloadMetrics(),
            databaseSizes: $this->databaseSizes(),
            bucketSizes: $this->bucketSizes(),
        );
    }

    /**
     * The metadata as reported (validation above guarantees its shape);
     * nested wildcard rules would otherwise strip items and drop additive
     * fields from newer agent releases. The metric samples are the exception:
     * they are stored in project_metric_minutes, and keeping up to
     * MAX_METRIC_ITEMS of them in agent_nodes.metadata would rewrite them on
     * every heartbeat for no reader. The database sizes go to `databases` and
     * the bucket usage to `buckets` the same way.
     *
     * @return array<string, mixed>
     */
    private function persistedMetadata(): array
    {
        $metadata = (array) $this->input('metadata', []);

        if (is_array($metadata['workloads'] ?? null)) {
            unset($metadata['workloads'][self::METRICS_KEY]);
        }

        unset($metadata[self::DATABASES_KEY], $metadata[self::BUCKETS_KEY]);

        return $metadata;
    }

    private function databaseSizes(): ?DatabaseSizesData
    {
        /** @var list<array{name: string, size_bytes: int}>|null $items */
        $items = $this->validated('metadata.'.self::DATABASES_KEY);

        return $items === null ? null : DatabaseSizesData::fromReport($items);
    }

    private function bucketSizes(): ?BucketSizesData
    {
        /** @var list<array{name: string, size_bytes: int}>|null $items */
        $items = $this->validated('metadata.'.self::BUCKETS_KEY);

        return $items === null ? null : BucketSizesData::fromReport($items);
    }

    private function workloadMetrics(): ?WorkloadMetricsData
    {
        /** @var list<array{project_id: string, deployment_id: string, replica: int, sampled_at: string, cpu_percent: float|int, memory_bytes: int, memory_limit_bytes: int|null, restart_count: int, process?: string|null}>|null $details */
        $details = $this->validated('metadata.workloads.'.self::METRICS_KEY);

        return $details === null ? null : WorkloadMetricsData::fromReport($details);
    }

    private function workloadReplicas(): ?WorkloadReplicasData
    {
        /** @var list<array{project_id: string, deployment_id: string, desired: int, ready: int}>|null $details */
        $details = $this->validated('metadata.workloads.replicas');

        return $details === null ? null : WorkloadReplicasData::fromReport($details);
    }

    private function workloadTraffic(): ?WorkloadTrafficData
    {
        /** @var list<array{project_id: string, deployment_id: string, last_request_at: string}>|null $details */
        $details = $this->validated('metadata.workloads.traffic');

        return $details === null ? null : WorkloadTrafficData::fromReport($details);
    }

    private function crashedWorkloads(): ?CrashedWorkloadsData
    {
        /** @var list<array{project_id: string, deployment_id: string, exit_code: int|null, oom_killed: bool, finished_at: string|null, process?: string|null, restart_count?: int|null, running?: bool|null}>|null $details */
        $details = $this->validated('metadata.workloads.crashed_details');

        if ($details === null) {
            return null;
        }

        return CrashedWorkloadsData::fromReport(
            details: $details,
            reportedCount: (int) $this->validated('metadata.detail_counts.crashed_details', count($details)),
        );
    }
}
