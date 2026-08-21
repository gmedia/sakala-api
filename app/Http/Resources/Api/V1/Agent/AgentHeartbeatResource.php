<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Models\AgentNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentNode */
final class AgentHeartbeatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'agent_id' => $this->resource->agent_id,
            'name' => $this->resource->name,
            'status' => $this->resource->status->value,
            'hostname' => $this->resource->hostname,
            'runtime_network' => $this->resource->runtime_network,
            'capabilities' => $this->resource->capabilities,
            'metadata' => $this->resource->metadata,
            'registered_at' => $this->resource->registered_at,
            'last_seen_at' => $this->resource->last_seen_at,
        ];
    }
}