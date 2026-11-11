<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Carbon\CarbonImmutable;

final readonly class AdminOverviewData
{
    /**
     * @param  list<array{date: string, count: int}>  $userGrowth
     * @param  list<array{source: string|null, count: int}>  $newUserSources
     */
    public function __construct(
        public TimeRangeData $range,
        public ?TimeRangeData $previousRange,
        public CarbonImmutable $generatedAt,
        public int $totalUsers,
        public int $totalProjects,
        public int $activeProjects,
        public int $newUsers,
        public int $newProjects,
        public DeploymentOutcomeCountsData $deployments,
        public int $previousNewUsers,
        public int $previousNewProjects,
        public DeploymentOutcomeCountsData $previousDeployments,
        public array $userGrowth,
        public FailureBreakdownData $failures,
        public array $newUserSources,
        public PilotValidationMetricsData $pilot,
    ) {}
}
