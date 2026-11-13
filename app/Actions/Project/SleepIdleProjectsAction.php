<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Admin\CreateSleepProjectCommandAction;
use App\Data\Project\IdleWindowData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Services\Runtime\PilotRuntimeLimitService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Put running projects to sleep after their workspace's idle window without a
 * request (ADR-015), so a quiet project gives its container slot back. The
 * window comes from the workspace's limit profile or, without one, from
 * `sakala.project.idle_sleep_minutes` (see PilotRuntimeLimitService). A window
 * of 0 never sleeps, so the sweep does nothing until some window is positive.
 *
 * Only nodes that advertise `idle-sleep` and whose last heartbeat carried a
 * traffic list take part: without that list nobody knows whether a project is
 * idle, and a project must never look idle for lack of data. A project is idle
 * when its last request, its active deployment's completion, and its last wake
 * or restart are all older than the window.
 */
final class SleepIdleProjectsAction
{
    /** Node capability of agents that report `metadata.workloads.traffic` and can sleep a project. */
    public const CAPABILITY = 'idle-sleep';

    /** `actor_id` of the SleepProject commands and audit events of this sweep. */
    public const ACTOR_ID = 'scheduler:sleep-idle';

    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly CreateSleepProjectCommandAction $createSleepCommand,
        private readonly PilotRuntimeLimitService $limits,
    ) {}

    /** Whether any workspace has a positive idle window. */
    public function enabled(): bool
    {
        return $this->windows(CarbonImmutable::now()) !== [];
    }

    /**
     * @return int The number of SleepProject commands created.
     */
    public function handle(): int
    {
        $windows = $this->windows(CarbonImmutable::now());

        if ($windows === []) {
            return 0;
        }

        $nodeIds = $this->nodesReportingTraffic();

        if ($nodeIds === []) {
            return 0;
        }

        $created = 0;

        foreach ($windows as $window) {
            $this->idleProjects(Project::query(), $nodeIds, $window)
                ->select('projects.id')
                ->lazyById(self::BATCH_SIZE, 'projects.id', 'id')
                ->each(function (Project $project) use ($nodeIds, $window, &$created): void {
                    try {
                        $created += $this->sleep($project->id, $nodeIds, $window) ? 1 : 0;
                    } catch (Throwable $exception) {
                        // One project must not keep the others awake.
                        report($exception);
                    }
                });
        }

        return $created;
    }

    /**
     * Positive idle windows, each with the workspaces it applies to: the
     * profiles that resolve to it, and workspaces without a profile when the
     * global setting does.
     *
     * @return list<IdleWindowData>
     */
    private function windows(CarbonImmutable $now): array
    {
        /** @var array<int, array{profile_ids: list<string>, unprofiled: bool}> $byMinutes */
        $byMinutes = [];
        $global = $this->limits->idleSleepMinutesFor(null);

        if ($global > 0) {
            $byMinutes[$global] = ['profile_ids' => [], 'unprofiled' => true];
        }

        foreach (LimitProfile::query()->get(['id', 'idle_sleep_minutes']) as $profile) {
            $minutes = $this->limits->idleSleepMinutesFor($profile);

            if ($minutes < 1) {
                continue;
            }

            $byMinutes[$minutes] ??= ['profile_ids' => [], 'unprofiled' => false];
            $byMinutes[$minutes]['profile_ids'][] = $profile->id;
        }

        $windows = [];

        foreach ($byMinutes as $minutes => $workspaces) {
            $windows[] = new IdleWindowData(
                minutes: $minutes,
                cutoff: $now->subMinutes($minutes),
                profileIds: $workspaces['profile_ids'],
                unprofiled: $workspaces['unprofiled'],
            );
        }

        return $windows;
    }

    /**
     * @param  list<string>  $nodeIds
     */
    private function sleep(string $projectId, array $nodeIds, IdleWindowData $window): bool
    {
        return DB::transaction(function () use ($projectId, $nodeIds, $window): bool {
            // Asked again under the lock: a request, a deploy, or a command may
            // have arrived since the list was read.
            /** @var Project|null $project */
            $project = $this->idleProjects(Project::query()->whereKey($projectId), $nodeIds, $window)
                ->lockForUpdate()
                ->first();

            if ($project === null) {
                return false;
            }

            /** @var Deployment $deployment */
            $deployment = Deployment::query()->findOrFail($project->active_deployment_id);

            $command = $this->createSleepCommand->handle(
                deployment: $deployment,
                reason: AgentCommand::IDLE_SLEEP_REASON,
                idempotencyKey: "idle-sleep:{$project->id}:{$deployment->id}:".$window->cutoff->format('YmdHi'),
                systemActorId: self::ACTOR_ID,
            );

            if (! $command->wasRecentlyCreated) {
                return false;
            }

            AuditEvent::create([
                'actor_type' => AuditEvent::SYSTEM_ACTOR_TYPE,
                'actor_id' => self::ACTOR_ID,
                'action' => 'project.idle_sleep_requested',
                'subject_type' => Project::class,
                'subject_id' => $project->id,
                'metadata' => [
                    'command_id' => $command->id,
                    'deployment_id' => $deployment->id,
                    'agent_node_id' => $deployment->agent_node_id,
                    'idle_sleep_minutes' => $window->minutes,
                    'last_request_at' => $project->last_request_at?->toISOString(),
                ],
            ]);

            return true;
        });
    }

    /**
     * @param  Builder<Project>  $query
     * @param  list<string>  $nodeIds
     * @return Builder<Project>
     */
    private function idleProjects(Builder $query, array $nodeIds, IdleWindowData $window): Builder
    {
        $cutoff = $window->cutoff;

        return $query
            ->where('runtime_status', RuntimeStatus::Running)
            ->where('status', '!=', ProjectStatus::Suspended)
            ->whereNotNull('active_deployment_id')
            ->whereHas('workspace', fn (Builder $workspace) => $workspace->where(fn (Builder $query) => $query
                ->whereIn('limit_profile_id', $window->profileIds)
                ->when($window->unprofiled, fn (Builder $query) => $query->orWhereNull('limit_profile_id'))))
            ->where(fn (Builder $query) => $query
                ->whereNull('last_request_at')
                ->orWhere('last_request_at', '<', $cutoff))
            ->whereHas('activeDeployment', fn (Builder $deployment) => $deployment
                ->where('status', DeploymentStatus::Succeeded)
                ->whereIn('agent_node_id', $nodeIds)
                ->whereNotNull('finished_at')
                ->where('finished_at', '<', $cutoff))
            ->whereDoesntHave('deployments', fn (Builder $deployments) => $deployments->active())
            // A project with a lifecycle command open is already changing.
            ->whereDoesntHave('agentCommands', fn (Builder $commands) => $commands
                ->open()
                ->whereIn('type', AgentCommandType::projectLifecycleCases()))
            // A wake or restart counts as activity, or a project woken by its
            // console button would fall asleep again on the next sweep.
            ->whereDoesntHave('agentCommands', fn (Builder $commands) => $commands
                ->whereIn('type', [AgentCommandType::WakeProject, AgentCommandType::RestartProject])
                ->where('status', AgentCommandStatus::Succeeded)
                ->where('completed_at', '>=', $cutoff));
    }

    /**
     * Nodes able to sleep a project whose last heartbeat said how recently each
     * workload served a request. An offline node's last list is stale.
     *
     * @return list<string>
     */
    private function nodesReportingTraffic(): array
    {
        return array_values(AgentNode::query()
            ->where('auth_status', AgentAuthStatus::Active)
            ->where('status', '!=', AgentNodeStatus::Offline)
            ->get(['id', 'capabilities', 'metadata'])
            ->filter(static fn (AgentNode $node): bool => in_array(self::CAPABILITY, $node->capabilities ?? [], true)
                && is_array(data_get($node->metadata, 'workloads.traffic')))
            ->map(static fn (AgentNode $node): string => $node->id)
            ->all());
    }
}
