<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Data\Agent\AgentHeartbeatData;
use App\Data\Agent\CrashedWorkloadsData;
use App\Data\Agent\WorkloadTrafficData;
use App\Enums\AgentNodeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AgentHeartbeatRequest extends FormRequest
{
    public const MAX_TRAFFIC_ITEMS = 500;

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
            // Optional, from nodes with the `idle-sleep` capability. The agent
            // caps it at its own max active containers, not at 50; the bound
            // here only keeps one heartbeat cheap. Like crashed_details, a
            // malformed item rejects the whole heartbeat.
            'metadata.workloads.traffic' => ['sometimes', 'array', 'max:'.self::MAX_TRAFFIC_ITEMS],
            'metadata.workloads.traffic.*' => ['required', 'array'],
            'metadata.workloads.traffic.*.project_id' => ['required', 'uuid'],
            'metadata.workloads.traffic.*.deployment_id' => ['required', 'uuid'],
            'metadata.workloads.traffic.*.last_request_at' => ['required', 'date'],

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
            // Persist the metadata as reported (validation above guarantees
            // its shape); nested wildcard rules would otherwise strip items
            // and drop additive fields from newer agent releases.
            metadata: (array) $this->input('metadata', []),
            status: AgentNodeStatus::from($this->validated('status')),
            protocolVersion: (int) $this->validated('metadata.protocol_version'),
            sentAt: CarbonImmutable::parse($this->validated('sent_at')),
            crashedWorkloads: $this->crashedWorkloads(),
            workloadTraffic: $this->workloadTraffic(),
        );
    }

    private function workloadTraffic(): ?WorkloadTrafficData
    {
        /** @var list<array{project_id: string, deployment_id: string, last_request_at: string}>|null $details */
        $details = $this->validated('metadata.workloads.traffic');

        return $details === null ? null : WorkloadTrafficData::fromReport($details);
    }

    private function crashedWorkloads(): ?CrashedWorkloadsData
    {
        /** @var list<array{project_id: string, deployment_id: string}>|null $details */
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
