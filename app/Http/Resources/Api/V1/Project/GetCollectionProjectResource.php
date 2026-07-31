<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetCollectionProjectResource extends JsonResource
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
            'name' => $this->resource->name,
            'repository_full_name' => $this->resource->repository_full_name,
            'branch' => $this->resource->branch,
            'thumbnail_url' => $this->resource->thumbnail_url,
            'runtime_status' => $this->resource->runtime_status?->value,
            'last_deployed_at' => $this->resource->last_deployed_at?->toAtomString(),
            'created_at' => $this->resource->created_at->toAtomString(),
        ];
    }
}
