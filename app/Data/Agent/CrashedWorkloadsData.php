<?php

declare(strict_types=1);

namespace App\Data\Agent;

final readonly class CrashedWorkloadsData
{
    /**
     * @param  array<string, string>  $projectIdByDeploymentId
     * @param  bool  $truncated  The agent found more crashes than the bounded
     *                           list holds, so absence from it proves nothing.
     */
    public function __construct(
        public array $projectIdByDeploymentId,
        public bool $truncated,
    ) {}

    /**
     * @param  list<array{project_id: string, deployment_id: string}>  $details
     */
    public static function fromReport(array $details, int $reportedCount): self
    {
        $projectIdByDeploymentId = [];

        foreach ($details as $detail) {
            $projectIdByDeploymentId[mb_strtolower($detail['deployment_id'])] = mb_strtolower($detail['project_id']);
        }

        return new self(
            projectIdByDeploymentId: $projectIdByDeploymentId,
            truncated: $reportedCount > count($details),
        );
    }

    public function lists(string $projectId, string $deploymentId): bool
    {
        return ($this->projectIdByDeploymentId[mb_strtolower($deploymentId)] ?? null) === mb_strtolower($projectId);
    }
}
