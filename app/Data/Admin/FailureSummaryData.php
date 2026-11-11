<?php

declare(strict_types=1);

namespace App\Data\Admin;

final readonly class FailureSummaryData
{
    public function __construct(
        public TimeRangeData $range,
        public FailureBreakdownData $current,
        public ?FailureBreakdownData $previous,
    ) {}
}
