<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\WorkloadReplicasData;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Support\Database\CaseUpdate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Store `deployments.replicas_desired` and `replicas_ready` from a heartbeat's
 * replica list. Only the node that runs a deployment speaks for it, and only
 * while that deployment serves its project: an older or sleeping deployment
 * keeps what it had. Readiness never changes `runtime_status`; a project with
 * some replicas down still serves, and only `crashed_details` (every replica
 * crashed) makes it `crashed`. One read and at most one write per heartbeat,
 * whatever the length of the list; an unchanged report writes nothing.
 */
final class RecordWorkloadReplicasAction
{
    public function handleWithinTransaction(AgentNode $agent, WorkloadReplicasData $report): void
    {
        if ($report->byDeploymentId === []) {
            return;
        }

        $deployments = Deployment::query()
            ->whereIn('id', array_keys($report->byDeploymentId))
            ->where('agent_node_id', $agent->id)
            ->whereHas('project', fn (Builder $project) => $project
                ->serving()
                ->whereColumn('projects.active_deployment_id', 'deployments.id'))
            ->get(['id', 'project_id', 'replicas_desired', 'replicas_ready']);

        $changed = [];

        foreach ($deployments as $deployment) {
            $reported = $report->byDeploymentId[mb_strtolower($deployment->id)] ?? null;

            if ($reported === null || $reported['project_id'] !== mb_strtolower($deployment->project_id)) {
                continue;
            }

            if ($deployment->replicas_desired !== $reported['desired'] || $deployment->replicas_ready !== $reported['ready']) {
                $changed[$deployment->id] = [
                    'replicas_desired' => $reported['desired'],
                    'replicas_ready' => $reported['ready'],
                ];
            }
        }

        CaseUpdate::apply('deployments', $changed, [
            'replicas_desired' => 'smallint',
            'replicas_ready' => 'smallint',
        ]);
    }
}
