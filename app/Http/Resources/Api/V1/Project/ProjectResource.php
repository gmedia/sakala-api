<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
final class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'repository_provider' => $this->repository_provider,
            'thumbnail_url' => $this->thumbnail_url,
            'repository_url' => $this->repository_url,
            'repository_full_name' => $this->repository_full_name,
            'branch' => $this->branch,
            'default_domain' => $this->default_domain,
            'status' => $this->status->value,
            'runtime_status' => $this->runtime_status->value,
            'detected_port' => $this->detected_port,
            'last_deployed_at' => $this->last_deployed_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
