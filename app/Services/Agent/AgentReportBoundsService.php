<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Data\Runtime\LogBoundsData;
use App\Models\AgentCommand;

final class AgentReportBoundsService
{
    public function resolve(AgentCommand $command): LogBoundsData
    {
        $maxLineLength = (int) config('sakala.pilot_limits.log_bounds.max_line_length', 4096);
        $maxBatchLines = (int) config('sakala.pilot_limits.log_bounds.max_batch_lines', 500);
        $maxTotalBytes = (int) config('sakala.pilot_limits.log_bounds.max_total_bytes', 10 * 1024 * 1024);

        $snapshot = $command->payload['log_bounds'] ?? [];

        if (! is_array($snapshot)) {
            $snapshot = [];
        }

        return new LogBoundsData(
            max_line_length: $this->boundedValue($snapshot, 'max_line_length', $maxLineLength, $maxLineLength),
            max_batch_lines: $this->boundedValue($snapshot, 'max_batch_lines', $maxBatchLines, $maxBatchLines),
            max_total_bytes: $this->boundedValue($snapshot, 'max_total_bytes', $maxTotalBytes, $maxTotalBytes),
        );
    }

    /**
     * A command snapshot can make a limit stricter, but never loosen the
     * current API-wide safety boundary.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function boundedValue(
        array $snapshot,
        string $key,
        int $fallback,
        int $currentLimit,
    ): int {
        $value = $snapshot[$key] ?? $fallback;

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return $currentLimit;
        }

        return min($currentLimit, max(1, (int) $value));
    }
}
