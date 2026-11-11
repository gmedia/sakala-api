<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\FailureSummaryData;
use App\Data\Admin\TimeRangeData;

final class GetFailureSummaryAction
{
    public function __construct(private readonly CountFailuresAction $failures) {}

    public function handle(TimeRangeData $range): FailureSummaryData
    {
        $previous = $range->previous();

        return new FailureSummaryData(
            range: $range,
            current: $this->failures->handle($range),
            previous: $previous === null ? null : $this->failures->handle($previous),
        );
    }
}
