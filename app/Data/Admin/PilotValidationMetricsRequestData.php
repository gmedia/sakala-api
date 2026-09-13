<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Carbon\CarbonImmutable;

final readonly class PilotValidationMetricsRequestData
{
    public function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {}
}
