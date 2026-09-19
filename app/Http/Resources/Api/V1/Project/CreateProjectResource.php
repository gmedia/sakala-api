<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\Project;
use App\Support\Project\ProjectPreviewPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 *
 * @property Project $resource
 */
final class CreateProjectResource extends JsonResource
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
            'repository_source' => $this->resource->github_installation_id === null
                ? 'public_url'
                : 'github_installation',
            'github_installation_id' => $this->resource->github_installation_id,
            'github_repository_id' => $this->resource->github_repository_id,
            'branch' => $this->resource->branch,
            'runtime_status' => $this->resource->runtime_status,
            'preview_status' => ProjectPreviewPresenter::status($this->resource),
            'inspection' => ProjectPreviewPresenter::inspection($this->resource),
            'inspection_error_code' => $this->resource->inspection_error_code,
            'created_at' => $this->resource->created_at->toAtomString(),
        ];
    }
}
