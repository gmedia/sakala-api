<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\GitHub;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GithubResource extends JsonResource
{
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
