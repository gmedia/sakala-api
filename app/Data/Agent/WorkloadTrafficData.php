<?php

declare(strict_types=1);

namespace App\Data\Agent;

use Carbon\CarbonImmutable;

/**
 * When the router last served a request for each running workload of a node,
 * from heartbeat `metadata.workloads.traffic`. Absence from the list means
 * "no request counted since the router started", never "idle".
 */
final readonly class WorkloadTrafficData
{
    /**
     * @param  array<string, array{project_id: string, last_request_at: CarbonImmutable}>  $byDeploymentId
     */
    public function __construct(
        public array $byDeploymentId,
    ) {}

    /**
     * @param  list<array{project_id: string, deployment_id: string, last_request_at: string}>  $details
     */
    public static function fromReport(array $details): self
    {
        $byDeploymentId = [];

        foreach ($details as $detail) {
            $deploymentId = mb_strtolower($detail['deployment_id']);
            $lastRequestAt = CarbonImmutable::parse($detail['last_request_at'])->utc();
            $known = $byDeploymentId[$deploymentId] ?? null;

            if ($known === null || $lastRequestAt->greaterThan($known['last_request_at'])) {
                $byDeploymentId[$deploymentId] = [
                    'project_id' => mb_strtolower($detail['project_id']),
                    'last_request_at' => $lastRequestAt,
                ];
            }
        }

        return new self($byDeploymentId);
    }
}
