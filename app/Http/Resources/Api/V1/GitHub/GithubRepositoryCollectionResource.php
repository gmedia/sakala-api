<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\GitHub;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GithubRepositoryCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'data' => GithubResource::collection($this->resource->repositories),
            'meta' => [
                'page' => $this->resource->page,
                'per_page' => $this->resource->perPage,
                'last_page' => $this->resource->lastPage,
                'has_next_page' => $this->resource->hasNextPage,
                'has_previous_page' => $this->resource->hasPreviousPage,
            ]
        ];
    }
}
