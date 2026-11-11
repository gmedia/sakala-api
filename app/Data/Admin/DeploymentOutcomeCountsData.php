<?php

declare(strict_types=1);

namespace App\Data\Admin;

/** Deployments created in a range, by outcome. */
final readonly class DeploymentOutcomeCountsData
{
    public function __construct(
        public int $succeeded,
        public int $failed,
        public int $cancelled,
        public int $inProgress,
    ) {}

    public function total(): int
    {
        return $this->succeeded + $this->failed + $this->cancelled + $this->inProgress;
    }
}
