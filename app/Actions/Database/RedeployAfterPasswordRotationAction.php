<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseRedeploySkipReason;
use App\Enums\DeploymentTrigger;
use App\Enums\ProjectStatus;
use App\Exceptions\Database\DatabaseEndpointUnavailableException;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\BuildRateLimitExceededException;
use App\Exceptions\Runtime\ProjectReleaseCommandUnsupportedException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\RepositoryAccessRevokedException;
use App\Models\AgentCommand;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Support\Database\DatabaseAudit;
use App\Support\Database\DatabasePasswordRotation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * After a rotation that asked for it took effect, redeploys every attached
 * project that has a live deployment, so its containers get the new
 * password. Runs from a queued job after the command's transaction, because
 * creating a deployment may ask GitHub. Each project goes through the normal
 * deployment path as whoever asked for the rotation, with every limit; a
 * project that cannot be redeployed is skipped with a reason and keeps
 * `redeploy_required`. The rotation is complete either way.
 *
 * A redeploy rebuilds the commit that is live, not the branch head, so
 * nobody ships unreviewed commits by rotating a password. Running again does
 * nothing: the outcome is recorded once, and each deployment has an
 * idempotency key per rotation.
 */
final class RedeployAfterPasswordRotationAction
{
    public function __construct(
        private readonly CreateDeploymentAction $createDeployment,
    ) {}

    public function handle(string $commandId): void
    {
        $command = AgentCommand::query()->find($commandId);

        if ($command === null
            || $command->type !== AgentCommandType::RotateDatabasePassword
            || $command->status !== AgentCommandStatus::Succeeded
            || ! DatabasePasswordRotation::redeploysAttached($command)
            || DatabasePasswordRotation::redeploysQueued($command)) {
            return;
        }

        $database = $command->database_id === null ? null : ManagedDatabase::query()->find($command->database_id);
        $requester = DatabasePasswordRotation::requester($command);
        $redeploys = [];

        if ($database !== null && $database->status->isProvisioned()) {
            $projects = $database->projects()->with(['activeDeployment', 'workspace'])->orderBy('name')->orderBy('projects.id')->get();

            foreach ($projects as $project) {
                $redeploys[] = ['project_id' => $project->id, ...$this->redeploy($command, $project, $requester)];
            }
        }

        $this->record($command, $database, $redeploys);
    }

    /** @return array{deployment_id: string|null, skipped_reason: string|null} */
    private function redeploy(AgentCommand $command, Project $project, ?User $requester): array
    {
        $active = $project->active_deployment_id === null ? null : $project->activeDeployment;

        if ($active === null) {
            return $this->skipped(DatabaseRedeploySkipReason::NoActiveDeployment);
        }

        if ($project->status === ProjectStatus::Suspended) {
            return $this->skipped(DatabaseRedeploySkipReason::ProjectSuspended);
        }

        if ($requester === null || ! $requester->can('deploy', $project)) {
            return $this->skipped(DatabaseRedeploySkipReason::RequesterCannotDeploy);
        }

        if ($requester->requiresLegalAcceptance()) {
            return $this->skipped(DatabaseRedeploySkipReason::LegalAcceptanceRequired);
        }

        // The live commit, unless the project has moved to another branch
        // since; then the branch head, as a manual redeploy would.
        $sameBranch = $active->branch === $project->branch && $active->commit_sha !== null;

        try {
            $deployment = $this->createDeployment->handle(
                project: $project,
                user: $requester,
                data: new CreateDeploymentData(
                    branch: $project->branch,
                    idempotencyKey: DatabasePasswordRotation::idempotencyKey($command),
                    trigger: DeploymentTrigger::DatabasePasswordRotated,
                    commit_sha: $sameBranch ? $active->commit_sha : null,
                    commit_message: $sameBranch ? $active->commit_message : null,
                ),
            );
        } catch (ActiveDeploymentLimitExceededException) {
            return $this->skipped(DatabaseRedeploySkipReason::ActiveDeploymentLimit);
        } catch (ActiveContainerLimitExceededException) {
            return $this->skipped(DatabaseRedeploySkipReason::ActiveContainerLimit);
        } catch (BuildRateLimitExceededException) {
            return $this->skipped(DatabaseRedeploySkipReason::BuildRateLimit);
        } catch (RepositoryAccessRevokedException) {
            return $this->skipped(DatabaseRedeploySkipReason::RepositoryAccessRevoked);
        } catch (ProjectSuspendedException) {
            return $this->skipped(DatabaseRedeploySkipReason::ProjectSuspended);
        } catch (DatabaseEndpointUnavailableException) {
            return $this->skipped(DatabaseRedeploySkipReason::DatabaseEndpointUnavailable);
        } catch (ProjectReleaseCommandUnsupportedException) {
            return $this->skipped(DatabaseRedeploySkipReason::ReleaseCommandUnsupported);
        } catch (Throwable $exception) {
            // One project's failure, such as GitHub not answering, must not
            // stop the others or undo the rotation.
            report($exception);

            return $this->skipped(DatabaseRedeploySkipReason::Failed);
        }

        return ['deployment_id' => $deployment->id, 'skipped_reason' => null];
    }

    /** @return array{deployment_id: null, skipped_reason: string} */
    private function skipped(DatabaseRedeploySkipReason $reason): array
    {
        return ['deployment_id' => null, 'skipped_reason' => $reason->value];
    }

    /** @param  list<array{project_id: string, deployment_id: string|null, skipped_reason: string|null}>  $redeploys */
    private function record(AgentCommand $command, ?ManagedDatabase $database, array $redeploys): void
    {
        DB::transaction(function () use ($command, $database, $redeploys): void {
            /** @var AgentCommand $locked */
            $locked = AgentCommand::query()->whereKey($command->id)->lockForUpdate()->firstOrFail();

            if (DatabasePasswordRotation::redeploysQueued($locked)) {
                return;
            }

            $locked->update([
                'request_context' => [
                    ...($locked->request_context ?? []),
                    DatabasePasswordRotation::REDEPLOYS_QUEUED_AT_KEY => now()->toAtomString(),
                    DatabasePasswordRotation::REDEPLOYS_KEY => $redeploys,
                ],
            ]);

            if ($database !== null) {
                DatabaseAudit::record(DatabasePasswordRotation::requester($locked), DatabaseAudit::PASSWORD_ROTATION_REDEPLOYS_QUEUED, $database, [
                    'command_id' => $locked->id,
                    'redeploys' => $redeploys,
                ]);
            }
        });
    }
}
