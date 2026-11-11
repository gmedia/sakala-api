<?php

declare(strict_types=1);

namespace App\Data\Admin;

final readonly class AuditEventFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public TimeRangeData $range,
        public ?string $actorType = null,
        public ?string $actorId = null,
        public ?string $action = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
    ) {}
}
