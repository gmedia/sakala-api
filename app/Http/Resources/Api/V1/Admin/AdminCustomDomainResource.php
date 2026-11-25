<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Http\Resources\Api\V1\CustomDomain\ProjectDomainResource;
use App\Models\ProjectDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom domain in the admin list (ADR-024): the project view of the
 * claim plus the project it belongs to.
 *
 * @mixin ProjectDomain
 *
 * @property ProjectDomain $resource
 */
final class AdminCustomDomainResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;

        return [
            /** The claim, as `GET app/projects/{project}/domains` shows it. */
            'domain' => new ProjectDomainResource($this->resource),
            /** The project the claim belongs to. */
            'project' => $project === null ? null : [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'workspace_id' => $project->workspace_id,
            ],
            /** The token is public in DNS; admins see who added the claim instead. */
            'created_by' => $this->resource->created_by,
        ];
    }
}
