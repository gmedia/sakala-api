<?php

declare(strict_types=1);

namespace App\Data\Agent;

final readonly class AgentReportAcknowledgementData
{
    public function __construct(
        public int $acceptedCount,
        public int $duplicateCount,
        public int $firstSequence,
        public int $lastSequence,
    ) {}
}
