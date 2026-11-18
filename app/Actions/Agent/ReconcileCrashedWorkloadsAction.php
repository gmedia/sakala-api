<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Actions\Deployment\AllocateDeploymentRealtimeSequenceAction;
use App\Data\Agent\CrashedWorkloadsData;
use App\Enums\NotificationCategory;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Notification\UserNotifier;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keep `runtime_status` honest about the live workload of the projects a node
 * serves. A heartbeat in the steady state costs one read and writes nothing,
 * so repeating a report changes no state and broadcasts nothing.
 */
final class ReconcileCrashedWorkloadsAction
{
    public function __construct(
        private readonly AllocateDeploymentRealtimeSequenceAction $allocateRealtimeSequence,
        private readonly UserNotifier $notifier,
    ) {}

    public function handleWithinTransaction(AgentNode $agent, CrashedWorkloadsData $report): void
    {
        $this->forgetRecoveredCrashes($agent, $report);

        $changed = $this->candidates($agent, $report)
            ->filter(fn (Project $project): bool => $this->nextStatus($project, $report) !== null);

        if ($changed->isEmpty()) {
            return;
        }

        // Deployment before project, like TransitionDeploymentAction, so a
        // heartbeat and a completing command cannot deadlock.
        /** @var Collection<string, Deployment> $deployments */
        $deployments = Deployment::query()
            ->whereKey($changed->pluck('active_deployment_id')->all())
            ->where('agent_node_id', $agent->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var Collection<int, Project> $projects */
        $projects = Project::query()
            ->with('user')
            ->whereKey($changed->pluck('id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // A newer attempt owns the status until it ends. Its outcome sets it, and
        // the next heartbeat corrects it if the old workload is still crashing.
        $deploying = Deployment::query()
            ->whereIn('project_id', $projects->pluck('id')->all())
            ->active()
            ->pluck('project_id')
            ->flip();

        foreach ($projects as $project) {
            if ($deploying->has($project->id)) {
                continue;
            }

            $deployment = $deployments->get((string) $project->active_deployment_id);
            $nextStatus = $this->nextStatus($project, $report);

            // Re-read under the lock: a deployment may have completed, or the
            // workload may have been stopped, since the unlocked read.
            if ($deployment === null || $nextStatus === null) {
                continue;
            }

            $project->update(['runtime_status' => $nextStatus]);

            $sequence = $this->allocateRealtimeSequence->handle($deployment);

            DeploymentUpdated::dispatch(
                deploymentId: $deployment->id,
                payload: [
                    ...DeploymentUpdated::payloadFor($deployment, $sequence),
                    'project_runtime_status' => $nextStatus->value,
                ],
            );

            // One notification per crash: the same crash can come back after a
            // failed redeploy reset the status, and it is not news the second time.
            if ($nextStatus === RuntimeStatus::Crashed && $deployment->crash_notified_at === null) {
                $deployment->update(['crash_notified_at' => now()]);

                $this->notifier->sendToProject(
                    $project,
                    NotificationCategory::ProjectIssue,
                    'Aplikasi berhenti',
                    "{$project->name} berhenti sendiri dan mungkin sedang dimulai ulang, jadi aksesnya bisa terputus-putus. Buka log deployment untuk melihat penyebabnya.",
                    $deployment->id,
                );
            }
        }
    }

    /**
     * A workload the agent no longer lists has recovered, so its next crash is a
     * new one. A truncated list proves nothing, so it clears nothing.
     */
    private function forgetRecoveredCrashes(AgentNode $agent, CrashedWorkloadsData $report): void
    {
        if ($report->truncated) {
            return;
        }

        Deployment::query()
            ->where('agent_node_id', $agent->id)
            ->whereNotNull('crash_notified_at')
            ->whereNotIn('id', array_keys($report->projectIdByDeploymentId))
            ->update(['crash_notified_at' => null]);
    }

    /**
     * Projects whose active deployment runs on this node and that are either
     * reported as crashed or still recorded as crashed.
     *
     * @return Collection<int, Project>
     */
    private function candidates(AgentNode $agent, CrashedWorkloadsData $report): Collection
    {
        return Project::query()
            ->whereIn(
                'active_deployment_id',
                Deployment::query()->select('id')->where('agent_node_id', $agent->id),
            )
            ->where(function ($query) use ($report): void {
                $query
                    ->where('runtime_status', RuntimeStatus::Crashed)
                    ->orWhereIn('active_deployment_id', array_keys($report->projectIdByDeploymentId));
            })
            ->get(['id', 'status', 'runtime_status', 'active_deployment_id']);
    }

    private function nextStatus(Project $project, CrashedWorkloadsData $report): ?RuntimeStatus
    {
        if ($report->lists($project->id, (string) $project->active_deployment_id)) {
            // A sleeping container is stopped on purpose and keeps the pointer,
            // so a report for it must not read as a crash.
            $intentionallyDown = $project->status === ProjectStatus::Suspended
                || $project->runtime_status === RuntimeStatus::Stopped
                || $project->runtime_status === RuntimeStatus::Sleeping;

            return $intentionallyDown || $project->runtime_status === RuntimeStatus::Crashed
                ? null
                : RuntimeStatus::Crashed;
        }

        return $project->runtime_status === RuntimeStatus::Crashed && ! $report->truncated
            ? RuntimeStatus::Running
            : null;
    }
}
