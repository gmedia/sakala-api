<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Deployment;

use App\Models\DeploymentLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeploymentLog
 */
final class DeploymentLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sequence' => $this->sequence,
            'stream' => $this->stream,
            'message' => $this->message,
            // The column is NOT NULL; Carbon only declares toISOString() as nullable.
            /** @var string */
            'recorded_at' => $this->recorded_at->toISOString(),
        ];
    }
}
