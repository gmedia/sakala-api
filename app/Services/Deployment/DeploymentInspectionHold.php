<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Actions\Deployment\TransitionDeploymentAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentFailureCategory;
use App\Enums\DeploymentStatus;
use App\Enums\InspectionHoldOutcome;
use App\Enums\ProjectInspectionStatus;
use App\Jobs\Deployment\SettleInspectionHoldsJob;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Holds the DeployProject command of a deployment created while the project's
 * inspection is still running and no port is configured. The port a
 * deployment runs on is fixed at creation (`configured_port`, then the port a
 * Dockerfile EXPOSEs, then 3000), and only the inspection knows the second, so
 * deploying before it finishes could pin a Dockerfile app to the wrong port.
 * Holding lets the Console deploy right after creating a project.
 *
 * - CreateDeploymentAction sets `awaiting_inspection_until` (now plus
 *   `sakala.deployments.inspection_wait_seconds`) and still creates the
 *   command with the rest of the payload, so variables and fingerprints are
 *   those of the click. Poll and claim skip a command whose deployment has it.
 * - settle() decides, under the deployment row lock (the lock claim and
 *   cancel take first too): a configured port, or an inspection that is no
 *   longer pending and did not fail, releases the command with the port in
 *   effect now; a failed inspection fails the deployment with its error code
 *   when the classifier knows it, `inspection_failed` otherwise; a pending one
 *   past the deadline fails it with `inspection_timeout`; otherwise it waits.
 * - ProjectInspectionOutcomeService queues SettleInspectionHoldsJob after an
 *   inspection outcome commits; the minute sweep settles every held
 *   deployment, which catches deadlines and a lost job.
 *
 * Settling is idempotent: the first run clears `awaiting_inspection_until`,
 * and a later run finds nothing held. The project row is read without a lock:
 * an outcome committed after the read queues its own settle.
 */
final class DeploymentInspectionHold
{
    /** Failure code for an inspection error the classifier does not know. */
    public const string FAILED_CODE = 'inspection_failed';

    /** Failure code for an inspection still pending at the deadline. */
    public const string TIMEOUT_CODE = 'inspection_timeout';

    public function __construct(
        private readonly TransitionDeploymentAction $transition,
        private readonly DeploymentFailureClassifier $classifier,
    ) {}

    /** Called under the project row lock by CreateDeploymentAction. */
    public function shouldHold(Project $lockedProject): bool
    {
        return $lockedProject->configured_port === null
            && $lockedProject->inspection_status === ProjectInspectionStatus::Pending;
    }

    public function deadline(): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds((int) config('sakala.deployments.inspection_wait_seconds'));
    }

    /** Queues a settle once the caller's transaction commits, when the project has a held deployment. */
    public function settleAfterCommit(Project $project): void
    {
        if ($this->heldQuery()->where('project_id', $project->id)->exists()) {
            SettleInspectionHoldsJob::dispatch($project->id)->afterCommit();
        }
    }

    /** @return array<value-of<InspectionHoldOutcome>, int> */
    public function settleProject(string $projectId): array
    {
        return $this->settleEach(
            $this->heldQuery()->where('project_id', $projectId)->orderBy('sequence')->get(['id']),
        );
    }

    /** @return array<value-of<InspectionHoldOutcome>, int> */
    public function settleAll(int $limit): array
    {
        return $this->settleEach(
            $this->heldQuery()->orderBy('awaiting_inspection_until')->limit($limit)->get(['id']),
        );
    }

    /**
     * Each deployment in its own transaction, so one cannot hold the locks of
     * the others.
     *
     * @param  Collection<int, Deployment>  $held  Only the ids are loaded.
     * @return array<value-of<InspectionHoldOutcome>, int>
     */
    private function settleEach(Collection $held): array
    {
        $counts = array_fill_keys(array_map(
            static fn (InspectionHoldOutcome $outcome): string => $outcome->value,
            InspectionHoldOutcome::cases(),
        ), 0);

        foreach ($held as $deployment) {
            $counts[$this->settle($deployment->id)->value]++;
        }

        return $counts;
    }

    private function settle(string $deploymentId): InspectionHoldOutcome
    {
        return DB::transaction(function () use ($deploymentId): InspectionHoldOutcome {
            $deployment = Deployment::query()->whereKey($deploymentId)->lockForUpdate()->first();

            if ($deployment === null || $deployment->awaiting_inspection_until === null) {
                return InspectionHoldOutcome::Gone;
            }

            // Cancelled, or failed by another path, while it waited.
            if ($deployment->status !== DeploymentStatus::Queued) {
                $deployment->update(['awaiting_inspection_until' => null]);

                return InspectionHoldOutcome::Gone;
            }

            $project = Project::withTrashed()->whereKey($deployment->project_id)->first();

            // Deleting the project cancels its deployments right after the
            // delete commits; that cancellation ends this one.
            if ($project === null || $project->trashed()) {
                return InspectionHoldOutcome::Waiting;
            }

            if ($project->configured_port === null && $project->inspection_status === ProjectInspectionStatus::Pending) {
                if ($deployment->awaiting_inspection_until->isFuture()) {
                    return InspectionHoldOutcome::Waiting;
                }

                return $this->fail($deployment, self::TIMEOUT_CODE, null);
            }

            if ($project->configured_port === null && $project->inspection_status === ProjectInspectionStatus::Failed) {
                $inspectionCode = $project->inspection_error_code;

                return $this->fail(
                    $deployment,
                    $this->failureCodeFor($inspectionCode),
                    $inspectionCode === null ? null : "Inspeksi proyek gagal: {$inspectionCode}",
                );
            }

            return $this->release($deployment, $project->effectivePort());
        });
    }

    private function release(Deployment $deployment, int $port): InspectionHoldOutcome
    {
        $command = $this->commandOf($deployment);

        // Claim takes the deployment lock held here, so the command is still
        // Pending; only the port changes, everything else is from the click.
        if ($command !== null && $command->status === AgentCommandStatus::Pending) {
            $command->update(['payload' => [...$command->payload, 'container_port' => $port]]);
        }

        $deployment->update([
            'container_port' => $port,
            'awaiting_inspection_until' => null,
        ]);

        return InspectionHoldOutcome::Released;
    }

    private function fail(Deployment $deployment, string $code, ?string $detail): InspectionHoldOutcome
    {
        $command = $this->commandOf($deployment);

        if ($command !== null) {
            AgentCommand::query()
                ->whereKey($command->id)
                ->where('status', AgentCommandStatus::Pending->value)
                ->update([
                    'status' => AgentCommandStatus::Cancelled->value,
                    'error_code' => $code,
                    'error_message' => 'The deployment failed while it waited for the project inspection.',
                    'updated_at' => now(),
                ]);
        }

        $deployment->update(['awaiting_inspection_until' => null]);

        $this->transition->handleWithinTransaction(
            deployment: $deployment,
            nextStatus: DeploymentStatus::Failed,
            failureData: $this->classifier->classify($code),
            failureDetail: $detail,
        );

        return InspectionHoldOutcome::Failed;
    }

    /**
     * An inspection error the classifier knows (a repository that is gone,
     * access GitHub refuses) explains the deployment's failure as well.
     */
    private function failureCodeFor(?string $inspectionCode): string
    {
        if ($inspectionCode === null
            || $this->classifier->classify($inspectionCode)->category === DeploymentFailureCategory::Unknown) {
            return self::FAILED_CODE;
        }

        return $inspectionCode;
    }

    private function commandOf(Deployment $deployment): ?AgentCommand
    {
        return AgentCommand::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', AgentCommandType::DeployProject)
            ->first();
    }

    /** @return Builder<Deployment> */
    private function heldQuery(): Builder
    {
        return Deployment::query()->whereNotNull('awaiting_inspection_until');
    }
}
