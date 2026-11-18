<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Models\AgentNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentNode */
final class AgentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'name' => $this->name,
            'description' => $this->description,
            'token_prefix' => $this->token_prefix,
            'auth_status' => $this->auth_status->value,
            'status' => $this->status->value,
            'desired_state' => $this->desired_state->value,
            'protocol_version' => $this->protocol_version,
            /** The region the node serves: the admin override, else the reported one. */
            'region' => $this->resource->effectiveRegion(),
            /** `host:port` where Sakala Edge reaches the node's router: the admin override, else the reported one. */
            'edge_address' => $this->resource->effectiveEdgeAddress(),
            'last_seen_at' => $this->last_seen_at?->toAtomString(),
            'created_at' => $this->created_at->toAtomString(),
            'updated_at' => $this->updated_at->toAtomString(),
        ];
    }
}
