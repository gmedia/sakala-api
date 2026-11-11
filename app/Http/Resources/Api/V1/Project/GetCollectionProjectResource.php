<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\Project;
use App\Support\Project\ProjectPreviewPresenter;
use App\Support\Project\ProjectSuspensionPresenter;
use App\Support\Workspace\WorkspaceSummaryPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 *
 * @property Project $resource
 */
final class GetCollectionProjectResource extends JsonResource
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
            'thumbnail_url' => $this->resource->thumbnail_url,
            'public_url' => $this->resource->publicUrl(),
            'runtime_status' => $this->resource->runtime_status,
            /**
             * Null unless the project is suspended. `reason` is what the admin wrote, as plain text;
             * both values are null for a suspended project without a suspend record.
             *
             * @var array{reason: string|null, suspended_at: string|null}|null
             */
            'suspension' => ProjectSuspensionPresenter::present($this->resource),
            'latest_deployment' => LatestDeploymentResource::fromProject($this->resource),
            'stack' => ProjectPreviewPresenter::stack($this->resource),
            'last_deployed_at' => $this->resource->last_deployed_at !== null
                ? $this->resource->last_deployed_at->toAtomString()
                : null,
            /**
             * The workspace that owns the project. Null only for a project that is being purged.
             */
            'workspace' => WorkspaceSummaryPresenter::forProject($this->resource),
            'created_at' => $this->resource->created_at->toAtomString(),
        ];
    }
}
