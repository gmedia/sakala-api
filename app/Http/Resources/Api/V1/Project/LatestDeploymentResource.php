<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a project card shows about the newest attempt, without a request per card.
 *
 * @mixin Deployment
 *
 * @property Deployment $resource
 */
final class LatestDeploymentResource extends JsonResource
{
    public static function fromProject(Project $project): ?self
    {
        return $project->latestDeployment === null ? null : new self($project->latestDeployment);
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
            /** @var int */
            'sequence' => $this->resource->sequence,
            'status' => $this->resource->status->value,
            'branch' => $this->resource->branch,
            /** @var string|null */
            'commit_sha' => $this->resource->commit_sha,
            /**
             * First line of the commit message, as plain text: its author controls it.
             *
             * @var string|null
             */
            'commit_message' => $this->resource->commit_message,
            'created_at' => $this->resource->created_at?->toAtomString(),
            /** @var string|null */
            'finished_at' => $this->resource->finished_at?->toAtomString(),
        ];
    }
}
