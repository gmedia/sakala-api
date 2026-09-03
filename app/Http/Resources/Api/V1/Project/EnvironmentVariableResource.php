<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnvironmentVariableResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'is_secret' => $this->resource->is_secret,
            'created_at' => $this->resource->created_at->toAtomString(),
        ];
    }
}
