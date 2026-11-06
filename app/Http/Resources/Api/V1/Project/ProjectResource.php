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
            'repository_source' => $this->resource->github_installation_id === null
                ? 'public_url'
                : 'github_installation',
            'github_installation_id' => $this->resource->github_installation_id,
            'github_repository_id' => $this->resource->github_repository_id,
            'branch' => $this->resource->branch,
            'default_domain' => $this->resource->default_domain,
            'public_url' => $this->resource->publicUrl(),
            'status' => $this->resource->status,
            'runtime_status' => $this->resource->runtime_status,
            'detected_port' => $this->resource->detected_port,
            /** @var int|null */
            'configured_port' => $this->resource->configured_port,
            'effective_port' => $this->resource->effectivePort(),
            /** @var int|null */
            'serving_port' => $this->resource->servingPort(),
            /** @var string|null */
            'build_command' => $this->resource->build_command,
            /** @var string|null */
            'start_command' => $this->resource->start_command,
            /** @var string|null */
            'root_directory' => $this->resource->root_directory,
            /** @var bool */
            'auto_deploy' => (bool) $this->resource->auto_deploy,
            /** @var bool */
            'redeploy_required' => $this->resource->requiresRedeploy(),
            /** @var string|null */
            'active_deployment_id' => $this->resource->active_deployment_id,
            'preview_status' => ProjectPreviewPresenter::status($this->resource),
            'inspection' => ProjectPreviewPresenter::inspection($this->resource),
            'stack' => ProjectPreviewPresenter::stack($this->resource),
            'inspection_error_code' => $this->resource->inspection_error_code,
            'last_deployed_at' => $this->resource->last_deployed_at !== null
                ? $this->resource->last_deployed_at->toAtomString()
                : null,
            'created_at' => $this->resource->created_at?->toAtomString(),
            'updated_at' => $this->resource->updated_at?->toAtomString(),
        ];
    }
}
