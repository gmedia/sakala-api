<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deployment;

use App\Enums\DeploymentEventLevel;
use App\Models\DeploymentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeploymentEvent
 */
final class DeploymentEventResource extends JsonResource
{
    /**
     * @return array{
     *     sequence: int,
     *     level: DeploymentEventLevel,
     *     type: string|null,
     *     message: string,
     *     metadata: object|null,
     *     occurred_at: string,
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $this->resource->metadata;

        return [
            'sequence' => $this->sequence,
            'level' => $this->level,
            'type' => $this->type,
            'message' => $this->message,
            // An empty PHP array would serialise as `[]`; this is always a JSON
            // object or null, matching the OpenAPI document.
            'metadata' => $metadata === null ? null : (object) $metadata,
            // The column is NOT NULL; Carbon only declares toISOString() as nullable.
            /** @var string */
            'occurred_at' => $this->occurred_at->toISOString(),
        ];
    }
}
