<?php

declare(strict_types=1);

namespace App\Data\Deployment;

use Carbon\CarbonInterface;

final readonly class PruneDeploymentLogsResultData
{
    public function __construct(
        public int $prunedLogsCount,
        public int $prunedEventsCount,
        public int $affectedDeploymentsCount,
        public bool $isDryRun,
        public int $retentionDays,
        public CarbonInterface $cutoffDate,
    ) {}
}
