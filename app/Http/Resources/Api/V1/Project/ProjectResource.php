<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\Project;
use App\Services\Runtime\PilotRuntimeLimitService;
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
            /**
             * Null unless the project is suspended. `reason` is what the admin wrote, as plain text;
             * both values are null for a suspended project without a suspend record.
             *
             * @var array{reason: string|null, suspended_at: string|null}|null
             */
            'suspension' => ProjectSuspensionPresenter::present($this->resource),
            'detected_port' => $this->resource->detected_port,
            /** @var int|null */
            'configured_port' => $this->resource->configured_port,
            'effective_port' => $this->resource->effectivePort(),
            /** @var int|null */
            'serving_port' => $this->resource->servingPort(),
            /** Containers the next deployment runs behind the node's router. Changing it applies from the next deployment. */
            'replicas' => $this->resource->replicas,
            /**
             * Most replicas a project of this workspace may run, from its limit profile; 1 without one.
             *
             * @var int
             */
            'max_replicas' => app(PilotRuntimeLimitService::class)->maxReplicasIn($this->resource->workspace),
            /**
             * Replicas the serving deployment runs, which can differ from `replicas` until the next deployment.
             * Null when nothing serves, like `serving_port`.
             */
            'serving_replicas' => $this->resource->servingReplicas(),
            /**
             * Replicas of the serving deployment that pass the router's health check, from the node's heartbeat.
             * Null when nothing serves or the node has not reported yet.
             */
            'replicas_ready' => $this->resource->servingReplicasReady(),
            /**
             * Region the project runs in, one of `GET app/regions`; `null` follows the workspace's default region, or
             * any region without one. Placement reads it only while the project has no workload on a node.
             */
            'region' => $this->resource->region,
            /** @var string|null */
            'build_command' => $this->resource->build_command,
            /** @var string|null */
            'start_command' => $this->resource->start_command,
            /** @var string|null */
            'root_directory' => $this->resource->root_directory,
            /** @var bool */
            'auto_deploy' => (bool) $this->resource->auto_deploy,
            /**
             * True while the serving deployment was created with another deploy-relevant configuration: branch, port,
             * build configuration, replicas, or environment variables. Changing a value back makes it false again.
             *
             * @var bool
             */
            'redeploy_required' => $this->resource->requiresRedeploy(),
            /** @var string|null */
            'active_deployment_id' => $this->resource->active_deployment_id,
            'latest_deployment' => LatestDeploymentResource::fromProject(
                $this->resource->loadMissing('latestDeployment'),
            ),
            'preview_status' => ProjectPreviewPresenter::status($this->resource),
            'inspection' => ProjectPreviewPresenter::inspection($this->resource),
            'stack' => ProjectPreviewPresenter::stack($this->resource),
            'inspection_error_code' => $this->resource->inspection_error_code,
            'last_deployed_at' => $this->resource->last_deployed_at !== null
                ? $this->resource->last_deployed_at->toAtomString()
                : null,
            /**
             * The workspace that owns the project. Null only for a project that is being purged.
             */
            'workspace' => WorkspaceSummaryPresenter::forProject($this->resource),
            'created_at' => $this->resource->created_at?->toAtomString(),
            'updated_at' => $this->resource->updated_at?->toAtomString(),
        ];
    }
}
