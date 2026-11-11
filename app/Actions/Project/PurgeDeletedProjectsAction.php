<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Admin\CreateStopProjectCommandAction;
use App\Data\Project\PurgeDeletedProjectsResultData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\RetiredProjectSlug;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Hard-deletes projects whose soft delete is older than the retention window,
 * with their deployments, logs, events, commands, environment variables, and
 * notifications. Foreign keys cascade to what is not deleted by hand here.
 *
 * The rows are also the only way to tell a node to stop a workload, so a
 * project a node still has to act on keeps its row until the node reported
 * back. Its secrets do not wait: stopping a container needs none of them, so
 * they go on time even when the node never returns. A revoked node can no
 * longer receive anything and does not hold a purge up.
 */
final class PurgeDeletedProjectsAction
{
    private const NON_TERMINAL_COMMANDS = [
        AgentCommandStatus::Pending,
        AgentCommandStatus::Claimed,
        AgentCommandStatus::Running,
    ];

    private const PURGED = 'purged';

    private const WAITING = 'waiting';

    private const SKIPPED = 'skipped';

    public function __construct(
        private readonly CreateStopProjectCommandAction $createStopCommand,
    ) {}

    public function handle(?int $days = null, bool $dryRun = false, int $batchSize = 100): PurgeDeletedProjectsResultData
    {
        $retentionDays = $days ?? (int) config('sakala.project.deleted_retention_days', 30);

        // Zero would purge what was deleted a moment ago, so a config typo must
        // stop the run instead of erasing the whole window.
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('Retention days must be at least 1.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1.');
        }

        $cutoff = CarbonImmutable::now()->subDays($retentionDays);
        $counts = [self::PURGED => 0, self::WAITING => 0, self::SKIPPED => 0, 'failed' => 0];

        Project::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->lazyById($batchSize)
            ->each(function (Project $project) use ($cutoff, $dryRun, &$counts): void {
                try {
                    $counts[$dryRun ? $this->classify($project) : $this->purge($project->id, $cutoff)]++;
                } catch (Throwable $exception) {
                    // One stuck project must not keep the others past their window.
                    report($exception);
                    $counts['failed']++;
                }
            });

        return new PurgeDeletedProjectsResultData(
            purgedCount: $counts[self::PURGED],
            waitingCount: $counts[self::WAITING],
            failedCount: $counts['failed'],
            isDryRun: $dryRun,
            retentionDays: $retentionDays,
            cutoffDate: $cutoff,
        );
    }

    private function classify(Project $project): string
    {
        return $this->nodeStillHasWork($project) || $this->unstoppedWorkload($project) !== null
            ? self::WAITING
            : self::PURGED;
    }

    private function purge(string $projectId, CarbonImmutable $cutoff): string
    {
        // Agent paths lock the project before they change what it runs, so what
        // is read under this lock cannot gain a command until the row is gone.
        return DB::transaction(function () use ($projectId, $cutoff): string {
            $project = Project::onlyTrashed()
                ->whereKey($projectId)
                ->where('deleted_at', '<=', $cutoff)
                ->lockForUpdate()
                ->first();

            if ($project === null) {
                return self::SKIPPED;
            }

            $nodeHasWork = $this->nodeStillHasWork($project);
            $workload = $this->unstoppedWorkload($project);

            if ($nodeHasWork || $workload !== null) {
                $this->eraseSecrets($project);

                if (! $nodeHasWork) {
                    $this->askAgainToStop($project, $workload);
                }

                Log::warning('A deleted project is past its retention, but a node still has to stop its workload.', [
                    'project_id' => $project->id,
                ]);

                return self::WAITING;
            }

            $this->deleteEverything($project);

            return self::PURGED;
        }, 3);
    }

    private function nodeStillHasWork(Project $project): bool
    {
        $revokedNodes = AgentNode::query()->where('auth_status', AgentAuthStatus::Revoked)->select('id');

        $commandInFlight = $this->commandsOf($project)
            ->whereIn('status', self::NON_TERMINAL_COMMANDS)
            ->where(fn (Builder $query) => $query->whereNull('agent_node_id')->orWhereNotIn('agent_node_id', $revokedNodes))
            ->exists();

        return $commandInFlight || Deployment::query()
            ->where('project_id', $project->id)
            ->active()
            ->whereNotNull('agent_node_id')
            ->whereNotIn('agent_node_id', $revokedNodes)
            ->exists();
    }

    /**
     * The deployment that still serves although nothing is asking it to stop,
     * which is what a failed or expired StopProject leaves behind.
     */
    private function unstoppedWorkload(Project $project): ?Deployment
    {
        if ($project->runtime_status === RuntimeStatus::Stopped || $project->active_deployment_id === null) {
            return null;
        }

        $deployment = Deployment::query()->find($project->active_deployment_id);

        if ($deployment === null || $deployment->agent_node_id === null) {
            return null;
        }

        $nodeRevoked = AgentNode::query()
            ->whereKey($deployment->agent_node_id)
            ->where('auth_status', AgentAuthStatus::Revoked)
            ->exists();

        $alreadyStopped = AgentCommand::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', AgentCommandType::StopProject)
            ->where('status', AgentCommandStatus::Succeeded)
            ->exists();

        return $nodeRevoked || $alreadyStopped ? null : $deployment;
    }

    /** The key carries the date, so a node that keeps failing is asked once a day. */
    private function askAgainToStop(Project $project, Deployment $workload): void
    {
        $this->createStopCommand->handle(
            deployment: $workload,
            reason: 'project_deleted',
            idempotencyKey: "project-purge:{$project->id}:{$workload->id}:".now()->format('Ymd'),
            removeImages: true,
        );
    }

    /** Also called when the owner deletes the account, without waiting for the window. */
    public function eraseSecrets(Project $project): void
    {
        $project->environmentVariables()->delete();

        // A DeployProject keeps a copy of the encrypted values in its payload.
        $this->commandsOf($project)
            ->whereNotIn('status', self::NON_TERMINAL_COMMANDS)
            ->update(['payload' => null]);
    }

    private function deleteEverything(Project $project): void
    {
        $commands = $this->commandsOf($project)->delete();
        $deployments = Deployment::query()->where('project_id', $project->id)->delete();

        // No owner left means the account was deleted, notifications included.
        $project->user?->notifications()->where('data->project_id', $project->id)->delete();

        RetiredProjectSlug::query()->insertOrIgnore(['slug' => $project->slug, 'retired_at' => now()]);

        $project->forceDelete();

        AuditEvent::create([
            'actor_type' => 'system',
            'actor_id' => 'projects:purge-deleted',
            'action' => 'project.purged',
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'metadata' => ['deployments' => $deployments, 'commands' => $commands],
        ]);
    }

    /** @return Builder<AgentCommand> */
    private function commandsOf(Project $project): Builder
    {
        return AgentCommand::query()->where(fn (Builder $query) => $query
            ->where('project_id', $project->id)
            ->orWhereIn('deployment_id', Deployment::query()->where('project_id', $project->id)->select('id')));
    }
}
