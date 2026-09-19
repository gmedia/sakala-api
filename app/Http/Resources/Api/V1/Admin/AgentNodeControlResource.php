<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\AgentNodeControlResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentNodeControlResultData
 */
final class AgentNodeControlResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'agent_node_id' => $this->node->id,
            'status' => $this->node->status->value,
            'desired_state' => $this->node->desired_state->value,
            'command' => [
                'id' => $this->command->id,
                'type' => $this->command->type->value,
                'status' => $this->command->status->value,
            ],
        ];
    }
}
