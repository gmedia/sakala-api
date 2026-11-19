<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Database\AttachedProjectDeployOutcomeData;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\DatabaseRedeploySkipReason;
use App\Enums\ProjectStatus;
use App\Exceptions\Database\DatabaseEndpointUnavailableException;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\BuildRateLimitExceededException;
use App\Exceptions\Runtime\ProjectReleaseCommandUnsupportedException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\RepositoryAccessRevokedException;
use App\Models\Project;
use App\Models\User;
use Throwable;

/**
 * Deploys a project attached to a database for someone who asked earlier
 * (a password rotation with `redeploy_attached`, a database created with
 * `deploy_when_ready`), through the normal deployment path with every limit.
 * Never throws: a project that cannot be deployed is skipped with a reason,
 * so one project's failure never stops the others. Called outside any
 * transaction, because creating a deployment may ask GitHub.
 */
final class AttachedProjectDeployer
{
    public function __construct(
        private readonly CreateDeploymentAction $createDeployment,
    ) {}

    public function deploy(Project $project, ?User $requester, CreateDeploymentData $data): AttachedProjectDeployOutcomeData
    {
        if ($project->status === ProjectStatus::Suspended) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::ProjectSuspended);
        }

        if ($requester === null || ! $requester->can('deploy', $project)) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::RequesterCannotDeploy);
        }

        if ($requester->requiresLegalAcceptance()) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::LegalAcceptanceRequired);
        }

        try {
            $deployment = $this->createDeployment->handle(project: $project, user: $requester, data: $data);
        } catch (ActiveDeploymentLimitExceededException) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::ActiveDeploymentLimit);
        } catch (ActiveContainerLimitExceededException) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::ActiveContainerLimit);
        } catch (BuildRateLimitExceededException) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::BuildRateLimit);
        } catch (RepositoryAccessRevokedException) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::RepositoryAccessRevoked);
        } catch (ProjectSuspendedException) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::ProjectSuspended);
        } catch (DatabaseEndpointUnavailableException) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::DatabaseEndpointUnavailable);
        } catch (ProjectReleaseCommandUnsupportedException) {
            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::ReleaseCommandUnsupported);
        } catch (Throwable $exception) {
            // Anything else, such as GitHub not answering, is reported and
            // must not stop the other projects.
            report($exception);

            return AttachedProjectDeployOutcomeData::skipped(DatabaseRedeploySkipReason::Failed);
        }

        return AttachedProjectDeployOutcomeData::started($deployment->id);
    }
}
