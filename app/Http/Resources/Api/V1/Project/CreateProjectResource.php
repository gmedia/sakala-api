<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreateProjectResource extends JsonResource
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
            'slug' => $this->resource->slug,
            'repository_full_name' => $this->resource->repository_full_name,
            'branch' => $this->resource->branch,
            'default_domain' => $this->resource->default_domain,
            'runtime_status' => $this->resource->runtime_status?->value,
            'created_at' => $this->resource->created_at->toAtomString(),
        ];
    }
}
