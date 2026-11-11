<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Http\Resources\Api\V1\Project\LatestDeploymentResource;
use App\Models\Project;
use App\Support\Project\ProjectSuspensionPresenter;
use App\Support\Workspace\WorkspaceSummaryPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project as admins list it. Environment variables never appear here.
 *
 * @mixin Project
 *
 * @property Project $resource
 */
final class AdminProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource;
        $creator = $project->user_id === null ? null : $project->user;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            /** `draft`, `active`, or `suspended`. */
            'status' => $project->status->value,
            /** `not_deployed`, `deploying`, `running`, `stopped`, `failed`, or `crashed`. */
            'runtime_status' => $project->runtime_status->value,
            'repository_url' => $project->repository_url,
            'repository_full_name' => $project->repository_full_name,
            'branch' => $project->branch,
            'public_url' => $project->publicUrl(),
            'active_deployment_id' => $project->active_deployment_id,
            /**
             * The owning workspace.
             *
             * @var array{id: string, name: string, slug: string, type: string}|null
             */
            'workspace' => WorkspaceSummaryPresenter::forProject($project),
            /**
             * Who created the project; null once that account is deleted.
             */
            'creator' => $creator === null ? null : ['id' => $creator->id, 'name' => $creator->name, 'username' => $creator->username],
            'latest_deployment' => LatestDeploymentResource::fromProject($project),
            /**
             * Reason and time of the suspension in force; null unless `status` is `suspended`.
             */
            'suspension' => ProjectSuspensionPresenter::present($project),
            'last_deployed_at' => $project->last_deployed_at?->toAtomString(),
            'created_at' => $project->created_at?->toAtomString(),
        ];
    }
}
