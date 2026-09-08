<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Data\Agent\AgentReportAcknowledgementData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AgentReportAcknowledgementData $resource
 */
final class AgentReportAcknowledgementResource extends JsonResource
{
    /** @return array<string, int> */
    public function toArray(Request $request): array
    {
        return [
            'accepted_count' => $this->resource->acceptedCount,
            'duplicate_count' => $this->resource->duplicateCount,
            'first_sequence' => $this->resource->firstSequence,
            'last_sequence' => $this->resource->lastSequence,
        ];
    }
}
