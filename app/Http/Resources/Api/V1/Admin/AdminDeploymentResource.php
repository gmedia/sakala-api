<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Http\Resources\Api\V1\Deployment\DeploymentResource;
use App\Models\Deployment;
use App\Support\Admin\DeploymentDuration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Any deployment, for admins: the deployment resource members see, plus who
 * asked for it, which project, and which node ran it. Environment variables
 * and command payloads are never part of it.
 *
 * @mixin Deployment
 *
 * @property Deployment $resource
 */
final class AdminDeploymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $deployment = $this->resource;
        $project = $deployment->project;
        $requester = $deployment->requested_by === null ? null : $deployment->requester;
        $node = $deployment->agent_node_id === null ? null : $deployment->agentNode;

        return [
            'deployment' => new DeploymentResource($deployment),
            'project' => $project === null ? null : [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'repository_url' => $project->repository_url,
                'workspace_id' => $project->workspace_id,
                'deleted' => $project->trashed(),
            ],
            'requested_by' => $requester === null ? null : ['id' => $requester->id, 'name' => $requester->name, 'username' => $requester->username],
            /** @var array{id: string, agent_id: string, name: string}|null */
            'agent_node' => $node === null ? null : ['id' => $node->id, 'agent_id' => $node->agent_id, 'name' => $node->name],
            'duration_seconds' => DeploymentDuration::seconds($deployment),
        ];
    }
}
