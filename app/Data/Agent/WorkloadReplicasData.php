<?php

declare(strict_types=1);

namespace App\Data\Agent;

/**
 * How many containers a node runs for each deployment with replicas, and how
 * many of them pass the router's health check, from heartbeat
 * `metadata.workloads.replicas`. A deployment that is not listed keeps its
 * last report.
 */
final readonly class WorkloadReplicasData
{
    /**
     * @param  array<string, array{project_id: string, desired: int, ready: int}>  $byDeploymentId  Keyed by lowercase deployment ID.
     */
    public function __construct(
        public array $byDeploymentId,
    ) {}

    /**
     * Items repeated for one deployment keep the last one, the node's latest
     * view.
     *
     * @param  list<array{project_id: string, deployment_id: string, desired: int, ready: int}>  $details
     */
    public static function fromReport(array $details): self
    {
        $byDeploymentId = [];

        foreach ($details as $detail) {
            $byDeploymentId[mb_strtolower($detail['deployment_id'])] = [
                'project_id' => mb_strtolower($detail['project_id']),
                'desired' => (int) $detail['desired'],
                'ready' => (int) $detail['ready'],
            ];
        }

        return new self($byDeploymentId);
    }
}
