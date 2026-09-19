<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Data\Agent\AgentNodeStateData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentNodeStateData
 */
final class AgentNodeStateResource extends JsonResource
{
    /**
     * @return array{desired_state: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'desired_state' => $this->desiredState->value,
        ];
    }
}
