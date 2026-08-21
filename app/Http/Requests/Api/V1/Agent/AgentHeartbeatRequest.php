<?php

namespace App\Http\Requests\Api\V1\Agent;

use App\Data\Agent\AgentHeartbeatData;
use App\Enums\AgentNodeStatus;
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
            'capabilities.*' => ['string', 'max:255'],
            'metadata' => ['required', 'array'],
            'sent_at' => ['required', 'date'],
        ];
    }

    public function toData(): AgentHeartbeatData
    {
        return new AgentHeartbeatData(
            status: AgentNodeStatus::from($this->validated('status')),
            hostname: $this->validated('hostname'),
            runtimeNetwork: $this->validated('runtime_network'),
            capabilities: $this->validated('capabilities'),
            metadata: $this->validated('metadata'),
            sentAt: CarbonImmutable::parse($this->validated('sent_at')),
        );
    }
}