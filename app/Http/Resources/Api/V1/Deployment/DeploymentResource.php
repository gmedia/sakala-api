<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deployment;

use App\Models\Deployment;
use App\Services\Deployment\DeploymentFailureClassifier;
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
        $failure = $this->resource->failure_code !== null
            ? app(DeploymentFailureClassifier::class)
                ->classify($this->resource->failure_code)
            : null;

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
            'finished_at' => $this->resource->finished_at
                ? $this->resource->finished_at->toAtomString()
                : null,
            'cancelled_at' => $this->resource->cancelled_at
                ? $this->resource->cancelled_at->toAtomString()
                : null,
            'failure_code' => $this->resource->failure_code,
            'failure_summary' => $this->resource->failure_summary,
            'failure' => $failure === null ? null : [
                'code' => $failure->code,
                'category' => $failure->category->value,
                'summary' => $failure->summary,
                'recovery_hint' => $failure->recoveryHint,
            ],
            'created_at' => $this->resource->created_at
                ? $this->resource->created_at->toAtomString()
                : null,
            'updated_at' => $this->resource->updated_at
                ? $this->resource->updated_at->toAtomString()
                : null,
        ];
    }
}
