<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\ProvisionedAgentData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ProvisionedAgentData $resource
 */
final class ProvisionedAgentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'agent' => new AdminAgentResource($this->resource->agent),
            /**
             * The bearer token for `SAKALA_AGENT_TOKEN`, shown this once. Sakala stores only its hash, so a lost token is
             * rotated, never recovered.
             */
            'token' => $this->resource->token,
        ];
    }
}
