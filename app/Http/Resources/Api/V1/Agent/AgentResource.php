<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Data\Agent\AgentData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentData */
final class AgentResource extends JsonResource
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
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'token_prefix' => $this->resource->token_prefix,
            'status' => $this->resource->status->value,
            'created_at' => $this->resource->created_at->toAtomString(),
            'updated_at' => $this->resource->updated_at->toAtomString(),
        ];
    }
}
