<?php

declare(strict_types=1);

namespace App\Services\Attachment;

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Attachment\AttachedProjectDeployOutcomeData;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\AttachedRedeploySkipReason;
use App\Enums\ProjectStatus;
use App\Exceptions\Bucket\BucketEndpointUnavailableException;
use App\Exceptions\Bucket\BucketKeyRotatingException;
use App\Exceptions\Bucket\BucketNotReadyException;
use App\Exceptions\Database\DatabaseEndpointUnavailableException;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Exceptions\Runtime\ActiveContainerLimitExceededException;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Exceptions\Runtime\BuildRateLimitExceededException;
use App\Exceptions\Runtime\ProjectReleaseCommandUnsupportedException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\ProjectWorkerProcessesUnsupportedException;
use App\Exceptions\Runtime\RepositoryAccessRevokedException;
use App\Exceptions\Runtime\WorkerContainerLimitExceededException;
use App\Exceptions\Valkey\ValkeyNodeMismatchException;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Exceptions\Valkey\ValkeyPasswordRotatingException;
use App\Models\Project;
use App\Models\User;
use Throwable;

/**
 * Deploys a project attached to a database, bucket, or Valkey store for
 * someone who asked earlier (a password or key rotation with `redeploy_attached`, a database
 * created with `deploy_when_ready`), through the normal deployment path with every limit.
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
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::ProjectSuspended);
        }

        if ($requester === null || ! $requester->can('deploy', $project)) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::RequesterCannotDeploy);
        }

        if ($requester->requiresLegalAcceptance()) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::LegalAcceptanceRequired);
        }

        try {
            $deployment = $this->createDeployment->handle(project: $project, user: $requester, data: $data);
        } catch (ActiveDeploymentLimitExceededException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::ActiveDeploymentLimit);
        } catch (ActiveContainerLimitExceededException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::ActiveContainerLimit);
        } catch (BuildRateLimitExceededException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::BuildRateLimit);
        } catch (RepositoryAccessRevokedException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::RepositoryAccessRevoked);
        } catch (ProjectSuspendedException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::ProjectSuspended);
        } catch (DatabaseEndpointUnavailableException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::DatabaseEndpointUnavailable);
        } catch (DatabaseNotReadyException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::DatabaseNotReady);
        } catch (BucketEndpointUnavailableException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::BucketEndpointUnavailable);
        } catch (BucketKeyRotatingException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::BucketKeyRotating);
        } catch (BucketNotReadyException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::BucketNotReady);
        } catch (ValkeyNotReadyException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::ValkeyNotReady);
        } catch (ValkeyPasswordRotatingException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::ValkeyPasswordRotating);
        } catch (ValkeyNodeMismatchException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::ValkeyNodeMismatch);
        } catch (ProjectReleaseCommandUnsupportedException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::ReleaseCommandUnsupported);
        } catch (WorkerContainerLimitExceededException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::WorkerContainerLimit);
        } catch (ProjectWorkerProcessesUnsupportedException) {
            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::WorkerProcessesUnsupported);
        } catch (Throwable $exception) {
            // Anything else, such as GitHub not answering, is reported and
            // must not stop the other projects.
            report($exception);

            return AttachedProjectDeployOutcomeData::skipped(AttachedRedeploySkipReason::Failed);
        }

        return AttachedProjectDeployOutcomeData::started($deployment->id);
    }
}
