<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Carbon\CarbonImmutable;

final readonly class UsageSignalsRequestData
{
    public function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {}
}
