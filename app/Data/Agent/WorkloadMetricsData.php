<?php

declare(strict_types=1);

namespace App\Data\Agent;

use Carbon\CarbonImmutable;

/**
 * Resource samples of the containers a node runs, from heartbeat
 * `metadata.workloads.metrics`: one item per replica per sample. Which of them
 * are kept is RecordWorkloadMetricsAction's call.
 */
final readonly class WorkloadMetricsData
{
    /**
     * @param  list<array{project_id: string, deployment_id: string, replica: int, sampled_at: CarbonImmutable, cpu_percent: float, memory_bytes: int, memory_limit_bytes: int|null, restart_count: int}>  $samples  IDs lowercase, times UTC.
     */
    public function __construct(
        public array $samples,
    ) {}

    /**
     * @param  list<array{project_id: string, deployment_id: string, replica: int|string, sampled_at: string, cpu_percent: float|int|string, memory_bytes: int|string, memory_limit_bytes: int|string|null, restart_count: int|string}>  $details
     */
    public static function fromReport(array $details): self
    {
        $samples = [];

        foreach ($details as $detail) {
            $samples[] = [
                'project_id' => mb_strtolower($detail['project_id']),
                'deployment_id' => mb_strtolower($detail['deployment_id']),
                'replica' => (int) $detail['replica'],
                'sampled_at' => CarbonImmutable::parse($detail['sampled_at'])->utc(),
                'cpu_percent' => (float) $detail['cpu_percent'],
                'memory_bytes' => (int) $detail['memory_bytes'],
                'memory_limit_bytes' => $detail['memory_limit_bytes'] === null ? null : (int) $detail['memory_limit_bytes'],
                'restart_count' => (int) $detail['restart_count'],
            ];
        }

        return new self($samples);
    }
}
