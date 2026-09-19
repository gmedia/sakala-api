<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\UsageSignalsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property UsageSignalsData $resource
 */
final class UsageSignalsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource->from->toIso8601String(),
            'to' => $this->resource->to->toIso8601String(),
            'signals' => collect($this->resource->signals)->map(fn (array $signal) => [
                'signal_type' => $signal['signal_type']->value,
                'count' => $signal['count'],
                'scope' => $signal['scope'],
                'scope_id' => $signal['scope_id'],
                'tags' => $signal['tags'],
                'collected_at' => $signal['collected_at']->toIso8601String(),
            ])->toArray(),
        ];
    }
}
