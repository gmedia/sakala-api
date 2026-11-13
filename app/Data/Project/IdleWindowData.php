<?php

declare(strict_types=1);

namespace App\Data\Project;

use Carbon\CarbonImmutable;

/**
 * One idle window of the idle sweep: the workspaces it applies to (by limit
 * profile, plus those without a profile when the global setting resolves to
 * it) and the moment before which their projects count as idle.
 */
final readonly class IdleWindowData
{
    /**
     * @param  list<string>  $profileIds
     */
    public function __construct(
        public int $minutes,
        public CarbonImmutable $cutoff,
        public array $profileIds,
        public bool $unprofiled,
    ) {}
}
