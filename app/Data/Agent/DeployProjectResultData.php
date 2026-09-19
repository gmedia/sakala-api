<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Data\Runtime\RuntimeResourceLimitsData;
use App\Enums\FinalizationDeferredReason;

/**
 * Typed view of the `result` an agent sends when completing DeployProject.
 * Tolerant of missing keys (the noop runtime sends `null`) and of the
 * `committed_result` wrapper the agent emits when the committed snapshot
 * was not an object.
 */
final readonly class DeployProjectResultData
{
    public function __construct(
        public ?RuntimeResourceLimitsData $requestedResources,
        public ?RuntimeResourceLimitsData $appliedResources,
        public bool $finalizationDeferred,
        public ?FinalizationDeferredReason $finalizationDeferredReason,
    ) {}

    /**
     * @param  array<string, mixed>|null  $result
     */
    public static function fromArray(?array $result): self
    {
        $result ??= [];

        $deferred = ($result['finalization_deferred'] ?? false) === true;
        $reasonValue = $result['finalization_deferred_reason'] ?? null;
        $reason = is_string($reasonValue) ? FinalizationDeferredReason::tryFrom($reasonValue) : null;

        $committed = $result['committed_result'] ?? null;
        $source = is_array($committed) ? $committed : $result;

        return new self(
            requestedResources: self::resources($source['requested_resources'] ?? null),
            appliedResources: self::resources($source['applied_resources'] ?? null),
            finalizationDeferred: $deferred,
            finalizationDeferredReason: $deferred ? $reason : null,
        );
    }

    private static function resources(mixed $value): ?RuntimeResourceLimitsData
    {
        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return RuntimeResourceLimitsData::fromArray($value);
    }
}
