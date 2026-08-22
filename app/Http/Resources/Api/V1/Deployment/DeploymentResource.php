<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deployment;

use App\Models\Deployment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deployment
 *
 * @property Deployment $resource
 */
final class DeploymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'project_id' => $this->resource->project_id,
            'sequence' => $this->resource->sequence,
            'branch' => $this->resource->branch,
            'status' => $this->resource->status->value,
            'trigger' => $this->resource->trigger->value,
            'commit_sha' => $this->resource->commit_sha,
            'commit_message' => $this->resource->commit_message,
            'image_reference' => $this->resource->image_reference,
            'requested_resources' => $this->resource->requested_resources,
            'effective_resources' => $this->resource->effective_resources,
            'started_at' => $this->resource->started_at?->toAtomString(),
            'finished_at' => $this->resource->finished_at?->toAtomString(),
            'cancelled_at' => $this->resource->cancelled_at?->toAtomString(),
            'failure_code' => $this->resource->failure_code,
            'failure_summary' => $this->resource->failure_summary,
            'created_at' => $this->resource->created_at?->toAtomString(),
            'updated_at' => $this->resource->updated_at?->toAtomString(),
        ];
    }
}
