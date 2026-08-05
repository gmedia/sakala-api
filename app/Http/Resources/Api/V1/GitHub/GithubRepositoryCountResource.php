<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\GitHub;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GithubRepositoryCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'total_repositories' => $this->resource,
        ];
    }
}