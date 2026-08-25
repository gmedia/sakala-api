<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Feedback;

use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Feedback
 *
 * @property Feedback $resource
 */
final class FeedbackResource extends JsonResource
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
            'category' => $this->resource->category->value,
            'message' => $this->resource->message,
            'project_id' => $this->resource->project_id,
            'deployment_id' => $this->resource->deployment_id,
            'consent' => $this->resource->consent,
            'created_at' => $this->resource->created_at?->toAtomString(),
            'updated_at' => $this->resource->updated_at?->toAtomString(),
        ];
    }
}
