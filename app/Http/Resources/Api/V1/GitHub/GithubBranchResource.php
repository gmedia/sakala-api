<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\GitHub;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GithubBranchResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource,
        ];
    }
}
