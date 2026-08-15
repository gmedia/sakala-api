<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\GitHub;

use App\Data\GitHub\GithubRepositoryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property GithubRepositoryData $resource
 */
final class GithubResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'full_name' => $this->resource->fullName,
            'clone_url' => $this->resource->cloneUrl,
            'default_branch' => $this->resource->defaultBranch,
            'pushed_at' => $this->resource->pushedAt,
            'private' => $this->resource->private,
        ];
    }
}