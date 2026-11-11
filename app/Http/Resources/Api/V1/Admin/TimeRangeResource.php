<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\TimeRangeData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TimeRangeData $resource
 */
final class TimeRangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** `7d`, `30d`, `90d`, `custom`, or `all`. */
            'key' => $this->resource->range->value,
            /**
             * Inclusive start, ISO 8601 in UTC; null for `all`.
             */
            'from' => $this->resource->from?->toIso8601String(),
            /** Exclusive end, ISO 8601 in UTC. */
            'to' => $this->resource->to->toIso8601String(),
            /** Days start at midnight in this time zone. */
            'timezone' => TimeRangeData::TIMEZONE,
        ];
    }
}
