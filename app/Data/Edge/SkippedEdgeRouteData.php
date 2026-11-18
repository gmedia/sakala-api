<?php

declare(strict_types=1);

namespace App\Data\Edge;

use App\Enums\EdgeRouteSkipReason;

/** A project that holds a workload but has no route, and why. */
final readonly class SkippedEdgeRouteData
{
    public function __construct(
        public string $host,
        public string $projectId,
        public EdgeRouteSkipReason $reason,
    ) {}

    /** @return array{host: string, project_id: string, reason: string} */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'project_id' => $this->projectId,
            'reason' => $this->reason->value,
        ];
    }

    /** @param  array{host: string, project_id: string, reason: string}  $skipped */
    public static function fromArray(array $skipped): self
    {
        return new self($skipped['host'], $skipped['project_id'], EdgeRouteSkipReason::from($skipped['reason']));
    }
}
