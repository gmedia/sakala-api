<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\UsageSignalType;
use Carbon\CarbonImmutable;

final readonly class UsageSignalsData
{
    /**
     * @param  list<array{signal_type: UsageSignalType, count: int, scope: string|null, scope_id: string|null, tags: array<string, mixed>, collected_at: CarbonImmutable}>  $signals
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $signals,
    ) {}
}
