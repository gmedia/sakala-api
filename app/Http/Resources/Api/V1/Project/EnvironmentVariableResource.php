<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\EnvironmentVariable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property EnvironmentVariable $resource
 */
class EnvironmentVariableResource extends JsonResource
{
    /** Set on writes, where the console has to say a redeploy is needed. */
    public ?bool $redeployRequired = null;

    public function withRedeployRequired(bool $redeployRequired): static
    {
        $this->redeployRequired = $redeployRequired;

        return $this;
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
            'key' => $this->resource->key,
            /** @var bool */
            'is_secret' => $this->resource->is_secret,
            // Readable without the reveal flow only when it is not a secret; a
            // secret never has this key at all.
            /** @var string */
            'value' => $this->when(! $this->resource->is_secret, fn () => $this->resource->encrypted_value),
            'created_at' => $this->resource->created_at->toAtomString(),
            /** @var bool */
            'redeploy_required' => $this->when($this->redeployRequired !== null, $this->redeployRequired),
        ];
    }
}
