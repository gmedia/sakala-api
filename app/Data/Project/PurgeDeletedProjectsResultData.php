<?php

declare(strict_types=1);

namespace App\Data\Project;

use Carbon\CarbonInterface;

final readonly class PurgeDeletedProjectsResultData
{
    public function __construct(
        public int $purgedCount,
        public int $waitingCount,
        public int $failedCount,
        public bool $isDryRun,
        public int $retentionDays,
        public CarbonInterface $cutoffDate,
    ) {}
}
