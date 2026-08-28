<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Data\Agent\AgentHeartbeatData;
use App\Enums\AgentNodeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AgentHeartbeatRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(AgentNodeStatus::class)],
            'hostname' => ['required', 'string', 'max:255'],
            'runtime_network' => ['required', 'string', 'max:120'],
            'capabilities' => ['required', 'array', 'max:50'],
            'capabilities.*' => ['required', 'string', 'max:255'],

            'metadata' => ['required', 'array'],
            'metadata.version' => ['required', 'string', 'max:50'],
            'metadata.protocol_version' => ['required', 'integer', 'min:1'],
            'metadata.runtime_driver' => ['required', 'string', 'max:50'],
            'metadata.lifecycle_state' => ['required', 'string', 'max:50'],
            'metadata.uptime_seconds' => ['required', 'integer', 'min:0'],

            'metadata.resources' => ['required', 'array'],
            'metadata.resources.cpu_total' => ['required', 'integer', 'min:1'],
            'metadata.resources.cpu_load_1m' => ['required', 'numeric', 'min:0'],
            'metadata.resources.memory_total_bytes' => ['required', 'integer', 'min:0'],
            'metadata.resources.memory_available_bytes' => ['required', 'integer', 'min:0'],
            'metadata.resources.disk_total_bytes' => ['required', 'integer', 'min:0'],
            'metadata.resources.disk_available_bytes' => ['required', 'integer', 'min:0'],
            'metadata.resources.workspace_used_bytes' => ['required', 'integer', 'min:0'],

            'metadata.workloads' => ['required', 'array'],
            'metadata.workloads.active' => ['required', 'integer', 'min:0'],
            'metadata.workloads.starting' => ['required', 'integer', 'min:0'],
            'metadata.workloads.unhealthy' => ['required', 'integer', 'min:0'],
            'metadata.workloads.stopped' => ['required', 'integer', 'min:0'],
            'metadata.workloads.unhealthy_details' => ['present', 'array'],

            'metadata.disk_pressure' => ['required', 'array'],
            'metadata.disk_pressure.state' => ['required', 'string', 'max:50'],
            'metadata.disk_pressure.minimum_workspace_free_bytes' => ['required', 'integer', 'min:0'],
            'metadata.disk_pressure.available_workspace_bytes' => ['required', 'integer', 'min:0'],

            'metadata.runtime_dependencies' => ['required', 'array'],
            'metadata.runtime_dependencies.git' => ['required', 'string', 'max:255'],
            'metadata.runtime_dependencies.docker' => ['required', 'string', 'max:255'],
            'metadata.runtime_dependencies.buildx' => ['required', 'string', 'max:255'],
            'metadata.runtime_dependencies.railpack' => ['required', 'string', 'max:255'],

            'metadata.execution' => ['required', 'array'],
            'metadata.execution.active_commands' => ['required', 'integer', 'min:0'],
            'metadata.execution.queued_local_commands' => ['required', 'integer', 'min:0'],
            'metadata.execution.capacity_waiting_commands' => ['required', 'integer', 'min:0'],
            'metadata.execution.active_builds' => ['required', 'integer', 'min:0'],
            'metadata.execution.maximum_concurrent_builds' => ['required', 'integer', 'min:0'],

            'metadata.startup_reconciliation' => ['required', 'array'],
            'metadata.startup_reconciliation.captured_at' => ['required', 'date'],
            'metadata.startup_reconciliation.inspected_containers' => ['required', 'integer', 'min:0'],
            'metadata.startup_reconciliation.cleaned_workspaces' => ['required', 'integer', 'min:0'],
            'metadata.startup_reconciliation.reattached_log_followers' => ['required', 'integer', 'min:0'],
            'metadata.startup_reconciliation.recovered_execution_records' => ['required', 'integer', 'min:0'],
            'metadata.startup_reconciliation.recovered_workloads' => ['present', 'array'],
            'metadata.startup_reconciliation.orphans' => ['present', 'array'],
            'metadata.startup_reconciliation.stale_routes' => ['present', 'array'],
            'metadata.startup_reconciliation.stale_images' => ['present', 'array'],

            'sent_at' => ['required', 'date'],
        ];
    }

    public function toData(): AgentHeartbeatData
    {
        return new AgentHeartbeatData(
            hostname: $this->validated('hostname'),
            runtimeNetwork: $this->validated('runtime_network'),
            capabilities: $this->validated('capabilities'),
            metadata: $this->validated('metadata'),
            status: AgentNodeStatus::from($this->validated('status')),
            sentAt: CarbonImmutable::parse($this->validated('sent_at')),
        );
    }
}
