<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deployment;

use App\Models\DeploymentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeploymentEvent
 */
final class DeploymentEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'level' => $this->level,
            'type' => $this->type,
            'message' => $this->message,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at?->toISOString(),
        ];
    }
}
