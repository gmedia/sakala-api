<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\WorkloadTrafficData;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Support\Database\CaseUpdate;
use Carbon\CarbonImmutable;

/**
 * Move `projects.last_request_at` forward from a heartbeat's traffic list. Only
 * the node that serves a project's active deployment speaks for it, and the
 * value never moves back. One read and at most one write per heartbeat,
 * whatever the length of the list.
 */
final class RecordWorkloadTrafficAction
{
    public function handleWithinTransaction(AgentNode $agent, WorkloadTrafficData $report): void
    {
        if ($report->byDeploymentId === []) {
            return;
        }

        $projects = Project::query()
            ->whereIn('active_deployment_id', array_keys($report->byDeploymentId))
            ->whereIn('active_deployment_id', Deployment::query()->select('id')->where('agent_node_id', $agent->id))
            ->get(['id', 'active_deployment_id', 'last_request_at']);

        // A clock running ahead on the node must not keep a project awake.
        $now = CarbonImmutable::now();
        $advanced = [];

        foreach ($projects as $project) {
            $reported = $report->byDeploymentId[mb_strtolower((string) $project->active_deployment_id)] ?? null;

            if ($reported === null || $reported['project_id'] !== mb_strtolower($project->id)) {
                continue;
            }

            $lastRequestAt = $reported['last_request_at']->min($now);

            if ($project->last_request_at === null || $lastRequestAt->greaterThan($project->last_request_at)) {
                $advanced[$project->id] = $project->fromDateTime($lastRequestAt);
            }
        }

        CaseUpdate::apply(
            'projects',
            array_map(static fn (?string $lastRequestAt): array => ['last_request_at' => $lastRequestAt], $advanced),
            ['last_request_at' => 'timestamptz'],
        );
    }
}
