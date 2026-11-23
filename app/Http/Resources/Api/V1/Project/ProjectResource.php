<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Actions\Project\RestartProjectAction;
use App\Enums\ProjectRestartState;
use App\Enums\VariableBuildReason;
use App\Models\ManagedBucket;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Project\AutoDeployPausePresenter;
use App\Support\Project\ProjectPreviewPresenter;
use App\Support\Project\ProjectSuspensionPresenter;
use App\Support\Project\VariableBuildPolicy;
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
     * The attached database with the names of the project's own variables, so
     * overridden connection variables can be flagged. This resource serves one
     * project at a time, so the two reads stay per request; the project list
     * (GetCollectionProjectResource) does not carry them.
     *
     * @return list<array<string, mixed>>
     */
    private function databases(): array
    {
        $databases = $this->resource->loadMissing('databases')->databases;

        if ($databases->isEmpty()) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = $this->resource->loadMissing('environmentVariables')->environmentVariables->pluck('key')->values()->all();

        return array_values($databases
            ->map(fn (ManagedDatabase $database): array => (new ProjectDatabaseResource($database))->withProjectKeys($keys)->resolve())
            ->all());
    }

    /**
     * The attached bucket with the names of the project's own variables, like
     * databases().
     *
     * @return list<array<string, mixed>>
     */
    private function buckets(): array
    {
        $buckets = $this->resource->loadMissing('buckets')->buckets;

        if ($buckets->isEmpty()) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = $this->resource->loadMissing('environmentVariables')->environmentVariables->pluck('key')->values()->all();

        return array_values($buckets
            ->map(fn (ManagedBucket $bucket): array => (new ProjectBucketResource($bucket))->withProjectKeys($keys)->resolve())
            ->all());
    }

    /**
     * The open restart a member asked for, if any.
     *
     * @return array{requested_at: string, status: ProjectRestartState}|null
     */
    private function restart(): ?array
    {
        $command = RestartProjectAction::openRestart($this->resource);

        return $command === null ? null : [
            'requested_at' => $command->created_at->toAtomString(),
            'status' => ProjectRestartState::Pending,
        ];
    }

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
            /**
             * Run once per deployment before the new version takes traffic, for example database migrations.
             *
             * @var string|null
             */
            'release_command' => $this->resource->release_command,
            /** @var string|null */
            'root_directory' => $this->resource->root_directory,
            /**
             * True when a push to the branch deploys now. False while paused after a rollback (see `auto_deploy_pause`).
             *
             * @var bool
             */
            'auto_deploy' => (bool) $this->resource->auto_deploy,
            /**
             * Non-null only while auto-deploy is paused after a rollback. `deployment_id` and `deployment_sequence` name
             * the rollback that paused it (null once deleted); `held_push_count` is how many pushes were recorded with
             * reason `auto_deploy_paused` since `paused_at`. `PATCH` with `auto_deploy` (true or false) clears it.
             */
            'auto_deploy_pause' => AutoDeployPausePresenter::present($this->resource),
            /**
             * Non-null while a restart a member asked for is queued or running on the node; null once it succeeded,
             * failed, or expired. `requested_at` is when it was asked for.
             */
            'restart' => $this->restart(),
            /**
             * True while the serving deployment was created with another deploy-relevant configuration: branch, port,
             * build configuration, replicas, environment variables, or the attached database or bucket (attaching,
             * detaching, and a rotated password or key). Changing a value back makes it false again.
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
            /**
             * What a change to the project's own variables does to the next deployment. `requires_build` is true when any
             * change builds (`reason` says why); false for a plain server, where only keys starting with one of
             * `build_time_prefixes` (case-sensitive) or equal to one of `build_time_keys` build, and the Console shows
             * `build_time_prefix` for such a key. An attached database's connection variables never build. See
             * docs/API_CONVENTIONS.md#variabel-yang-memengaruhi-build.
             *
             * @var array{requires_build: bool, reason: VariableBuildReason|null, build_time_prefixes: list<string>, build_time_keys: list<string>}
             */
            'variable_changes' => VariableBuildPolicy::present($this->resource),
            'last_deployed_at' => $this->resource->last_deployed_at !== null
                ? $this->resource->last_deployed_at->toAtomString()
                : null,
            /**
             * The managed database attached to the project, as a list of at most one: a project uses one database at a
             * time. Its connection variables reach the next deployment; see each item's `variables`. `deploy_when_ready`
             * is the deployment asked for when the database was created for this project, or `null`.
             *
             * @var list<array{id: string, name: string, status: string, region: string|null, variables: list<string>, overridden_variables: list<string>, deploy_when_ready: array{status: 'waiting'|'started'|'skipped', requested_at: string, deployment_id: string|null, skipped_reason: string|null}|null}>
             */
            'databases' => $this->databases(),
            /**
             * The managed bucket attached to the project, as a list of at most one: a project uses one bucket at a
             * time. Its variables reach the next deployment; see each item's `variables`.
             *
             * @var list<array{id: string, name: string, status: string, region: string|null, variables: list<string>, overridden_variables: list<string>}>
             */
            'buckets' => $this->buckets(),
            /**
             * The workspace that owns the project. Null only for a project that is being purged.
             */
            'workspace' => WorkspaceSummaryPresenter::forProject($this->resource),
            'created_at' => $this->resource->created_at?->toAtomString(),
            'updated_at' => $this->resource->updated_at?->toAtomString(),
        ];
    }
}
