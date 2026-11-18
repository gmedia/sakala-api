<?php

declare(strict_types=1);

namespace App\Data\Edge;

use App\Enums\EdgeHealthReason;
use Carbon\CarbonImmutable;

/** The health of one Edge instance at one moment (EvaluateEdgeHealthAction). */
final readonly class EdgeHealthData
{
    /**
     * @param  bool|null  $healthy  Null for a revoked token or one never seen: not evaluated, never alerted.
     * @param  list<EdgeHealthReason>  $reasons  The conditions that held longer than the threshold; empty when healthy or unknown.
     * @param  CarbonImmutable|null  $unhealthySince  The earliest moment one of `reasons` crossed the threshold.
     */
    public function __construct(
        public ?bool $healthy,
        public array $reasons,
        public ?CarbonImmutable $unhealthySince,
    ) {}

    public static function unknown(): self
    {
        return new self(null, [], null);
    }

    public function isUnhealthy(): bool
    {
        return $this->healthy === false;
    }
}
