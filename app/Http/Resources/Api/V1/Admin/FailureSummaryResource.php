<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\FailureSummaryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property FailureSummaryData $resource
 */
final class FailureSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $current = $this->resource->current;
        $previous = $this->resource->previous;

        return [
            'range' => new TimeRangeResource($this->resource->range),
            /** Failed deployments in the range. */
            'total' => $current->total,
            /**
             * Failed deployments in the window of equal length before the range; null for `range=all`.
             */
            'previous_total' => $previous?->total,
            /**
             * Every `DeploymentFailureCategory`, most frequent first. `previous_count` is null for `range=all`.
             *
             * @var list<array{category: string, count: int, previous_count: int|null}>
             */
            'categories' => array_map(
                static fn (string $category, int $count): array => [
                    'category' => $category,
                    'count' => $count,
                    'previous_count' => $previous?->categories[$category],
                ],
                array_keys($current->categories),
                array_values($current->categories),
            ),
            /**
             * Every failure code seen in the range, most frequent first. `code` is null for failures recorded without one.
             */
            'codes' => $current->codes,
        ];
    }
}
