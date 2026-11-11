<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\AgentNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A runtime node. Carries the token prefix for identification, never the
 * token or its hash.
 *
 * @mixin AgentNode
 *
 * @property AgentNode $resource
 */
final class AdminAgentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $node = $this->resource;
        /** @var array<string, mixed> $metadata */
        $metadata = $node->metadata ?? [];

        return [
            'id' => $node->id,
            /** The ID the agent sends as `X-Agent-Id`. */
            'agent_id' => $node->agent_id,
            'name' => $node->name,
            /** @var string|null */
            'description' => $node->description,
            /** @var string|null */
            'token_prefix' => $node->token_prefix,
            /** `active`, `revoked`, or `suspended`. */
            'auth_status' => $node->auth_status->value,
            /** The status the node last reported, or `offline` once its heartbeat stopped. */
            'status' => $node->status->value,
            /** What the control plane wants: `active`, `draining`, `drained`, or `maintenance`. */
            'desired_state' => $node->desired_state->value,
            'protocol_version' => $node->protocol_version,
            /**
             * The sakala-agent version from the last heartbeat.
             *
             * @var string|null
             */
            'agent_version' => isset($metadata['version']) && is_string($metadata['version']) ? $metadata['version'] : null,
            /** @var string|null */
            'hostname' => $node->hostname,
            /**
             * The Docker network workloads join on the node, not an IP address.
             *
             * @var string|null
             */
            'runtime_network' => $node->runtime_network,
            /**
             * For example `docker`, `caddy`, `railpack`.
             *
             * @var list<string>
             */
            'capabilities' => array_values($node->capabilities ?? []),
            /**
             * Projects whose serving deployment runs on this node.
             *
             * @var int|null
             */
            'active_workload_count' => $this->whenCounted('active_workload_count'),
            /**
             * Commands for this node that are Pending, Claimed, or Running.
             *
             * @var int|null
             */
            'open_commands_count' => $this->whenCounted('open_commands_count'),
            /**
             * When the node record and its token were created.
             */
            'registered_at' => $node->registered_at?->toAtomString(),
            /**
             * The last heartbeat; null until the agent first connects.
             */
            'last_seen_at' => $node->last_seen_at?->toAtomString(),
            /** @var string|null */
            'created_at' => $node->created_at?->toAtomString(),
            /** @var string|null */
            'updated_at' => $node->updated_at?->toAtomString(),
        ];
    }
}
