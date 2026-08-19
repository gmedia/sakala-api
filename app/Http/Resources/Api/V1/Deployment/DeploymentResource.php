<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deployment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeploymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'project_id' => $this->resource->project_id,
            'sequence' => $this->resource->sequence,
            'branch' => $this->resource->branch,
            'status' => $this->resource->status->value,
            'trigger' => $this->resource->trigger->value,
            'commit_sha' => $this->resource->commit_sha,
            'commit_message' => $this->resource->commit_message,
            'started_at' => $this->resource->started_at,
            'finished_at' => $this->resource->finished_at,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
