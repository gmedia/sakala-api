<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 *
 * @property Project $resource
 */
final class ProjectResource extends JsonResource
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
            'repository_provider' => $this->resource->repository_provider,
            'thumbnail_url' => $this->resource->thumbnail_url,
            'repository_url' => $this->resource->repository_url,
            'repository_full_name' => $this->resource->repository_full_name,
            'repository_source' => $this->resource->github_installation_id === null ? 'public_url' : 'github_installation',
            'github_installation_id' => $this->resource->github_installation_id,
            'github_repository_id' => $this->resource->github_repository_id,
            'branch' => $this->resource->branch,
            'default_domain' => $this->resource->default_domain,
            'status' => $this->resource->status->value,
            'runtime_status' => $this->resource->runtime_status->value,
            'detected_port' => $this->resource->detected_port,
            'last_deployed_at' => $this->resource->last_deployed_at?->toAtomString(),
            'created_at' => $this->resource->created_at?->toAtomString(),
            'updated_at' => $this->resource->updated_at?->toAtomString(),
        ];
    }
}
