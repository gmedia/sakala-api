<?php

declare(strict_types=1);

namespace App\Data\Agent;

use Carbon\CarbonImmutable;

final readonly class CrashedWorkloadsData
{
    /**
     * @param  array<string, string>  $projectIdByDeploymentId  Web processes only.
     * @param  bool  $truncated  The agent found more crashes than the bounded
     *                           list holds, so absence from it proves nothing.
     * @param  array<string, array{project_id: string, processes: array<string, array{exit_code: int|null, oom_killed: bool, finished_at: string|null, restart_count: int|null, running: bool|null}>}>  $workersByDeploymentId  Crashing worker processes (ADR-022), keyed by lowercase deployment ID, then by process name.
     */
    public function __construct(
        public array $projectIdByDeploymentId,
        public bool $truncated,
        public array $workersByDeploymentId = [],
    ) {}

    /**
     * Items with a `process` are worker containers (ADR-022): they never mark
     * the web process crashed, and are kept apart for RecordWorkerCrashesAction.
     *
     * @param  list<array{project_id: string, deployment_id: string, exit_code?: int|null, oom_killed?: bool, finished_at?: string|null, process?: string|null, restart_count?: int|null, running?: bool|null}>  $details
     */
    public static function fromReport(array $details, int $reportedCount): self
    {
        $projectIdByDeploymentId = [];
        $workers = [];

        foreach ($details as $detail) {
            $deploymentId = mb_strtolower($detail['deployment_id']);
            $projectId = mb_strtolower($detail['project_id']);
            $process = $detail['process'] ?? null;

            if ($process === null) {
                $projectIdByDeploymentId[$deploymentId] = $projectId;

                continue;
            }

            $workers[$deploymentId]['project_id'] = $projectId;
            $workers[$deploymentId]['processes'][$process] = [
                'exit_code' => $detail['exit_code'] ?? null,
                'oom_killed' => $detail['oom_killed'] ?? false,
                'finished_at' => isset($detail['finished_at']) ? CarbonImmutable::parse($detail['finished_at'])->utc()->toIso8601ZuluString() : null,
                'restart_count' => $detail['restart_count'] ?? null,
                'running' => $detail['running'] ?? null,
            ];
        }

        return new self(
            projectIdByDeploymentId: $projectIdByDeploymentId,
            truncated: $reportedCount > count($details),
            workersByDeploymentId: $workers,
        );
    }

    public function lists(string $projectId, string $deploymentId): bool
    {
        return ($this->projectIdByDeploymentId[mb_strtolower($deploymentId)] ?? null) === mb_strtolower($projectId);
    }
}
