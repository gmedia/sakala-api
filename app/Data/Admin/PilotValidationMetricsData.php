<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Carbon\CarbonImmutable;

final readonly class PilotValidationMetricsData
{
    /**
     * @param  array<string, int>  $failureCategories
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $activatedUsers,
        public int $successfulDeployments,
        public int $uniqueDeployers,
        public int $repeatDeployers,
        public array $failureCategories,
        public int $pilotFeedbackCount,
    ) {}
}
