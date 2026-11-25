<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\CrashedWorkloadsData;
use App\Data\Notification\ProjectAlertMailData;
use App\Enums\NotificationCategory;
use App\Enums\ProjectAlert;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Notification\UserNotifier;
use App\Support\Database\RowLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keep `deployments.crashed_processes` honest about the worker processes
 * (ADR-022) of the deployments a node runs, from the heartbeat's
 * `crashed_details` items that carry a `process`. A crashing worker never
 * changes `runtime_status`: the web process keeps serving. Members are told
 * once when a worker starts crashing; a worker the list no longer holds has
 * recovered, unless the list was truncated.
 *
 * Like ReconcileCrashedWorkloadsAction, the steady state costs one read and
 * writes nothing: only a change of the set of crashing workers is written.
 */
final class RecordWorkerCrashesAction
{
    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    public function handleWithinTransaction(AgentNode $agent, CrashedWorkloadsData $report): void
    {
        $reported = $report->workersByDeploymentId;

        /** @var Collection<int, Deployment> $deployments */
        $deployments = Deployment::query()
            ->where('agent_node_id', $agent->id)
            ->where(function ($query) use ($reported): void {
                $query->whereNotNull('crashed_processes')
                    ->orWhereIn('id', array_keys($reported));
            })
            ->orderBy('id')
            // FOR NO KEY UPDATE (see HeartbeatAgentAction): a command created
            // for one of these deployments checks its foreign key without
            // waiting for the heartbeat, which writes projects after this.
            ->lock(RowLock::NO_KEY_UPDATE)
            ->get();

        foreach ($deployments as $deployment) {
            $entry = $reported[mb_strtolower($deployment->id)] ?? null;

            if ($entry !== null && $entry['project_id'] !== mb_strtolower($deployment->project_id)) {
                continue;
            }

            $stored = $deployment->crashed_processes ?? [];
            $now = $entry['processes'] ?? [];

            // A truncated list cannot prove a worker recovered.
            $kept = $report->truncated ? $stored : array_intersect_key($stored, $now);
            $started = array_diff_key($now, $stored);

            if ($started === [] && count($kept) === count($stored)) {
                continue;
            }

            $since = CarbonImmutable::now()->toIso8601ZuluString();
            $next = $kept;

            foreach ($started as $process => $detail) {
                $next[$process] = [...$detail, 'since' => $since];
            }

            ksort($next, SORT_STRING);
            $deployment->update(['crashed_processes' => $next === [] ? null : $next]);

            if ($started !== []) {
                $this->notify($deployment, array_keys($started));
            }
        }
    }

    /** @param  list<string>  $processes */
    private function notify(Deployment $deployment, array $processes): void
    {
        $project = Project::query()->find($deployment->project_id);

        // Only the serving deployment's workers are news; a superseded
        // candidate or a stopped project is not.
        if ($project === null
            || $project->active_deployment_id !== $deployment->id
            || $project->status === ProjectStatus::Suspended
            || in_array($project->runtime_status, [RuntimeStatus::Sleeping, RuntimeStatus::Stopped], true)) {
            return;
        }

        foreach ($processes as $process) {
            $this->notifier->sendToProject(
                $project,
                NotificationCategory::ProjectIssue,
                'Worker berhenti',
                "Worker {$process} di {$project->name} berhenti sendiri dan sedang dimulai ulang. Aplikasi web tetap berjalan. Buka log deployment dan pilih proses {$process} untuk melihat penyebabnya.",
                $deployment->id,
            );

            $this->notifier->emailProject($project, new ProjectAlertMailData(
                alert: ProjectAlert::WorkerCrashed,
                projectId: $project->id,
                projectName: $project->name,
                deploymentId: $deployment->id,
                deploymentSequence: $deployment->sequence,
                summary: $process,
            ), $deployment->requester);
        }
    }
}
