<?php

declare(strict_types=1);

namespace App\Data\Agent;

final readonly class ReportDeploymentEventData
{
    /**
     * @param  list<DeploymentEventReportItemData>  $items
     */
    public function __construct(
        public array $items,
        public ?string $idempotencyKey,
        public int $requestBytes,
    ) {}
}
