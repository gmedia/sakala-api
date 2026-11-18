<?php

declare(strict_types=1);

namespace App\Data\Project;

final readonly class MoveProjectDomainsResultData
{
    /**
     * @param  list<UnexpectedProjectDomainData>  $unexpected  Projects on neither `<slug>.<from>` nor `<slug>.<to>`; never changed.
     * @param  list<string>  $reservedSlugs  Slugs of projects whose slug is now reserved, such as `staging`; never changed.
     */
    public function __construct(
        public string $from,
        public string $to,
        public bool $isDryRun,
        public int $movedCount,
        public int $movedDeletedCount,
        public int $alreadyMovedCount,
        public array $unexpected,
        public array $reservedSlugs,
    ) {}
}
