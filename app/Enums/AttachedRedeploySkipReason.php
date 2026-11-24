<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the API did not deploy an attached project on someone's behalf: after
 * a database password or bucket key rotation that asked for redeploys, or
 * once a database created with `deploy_when_ready` became ready. The project
 * keeps `redeploy_required` and can be deployed by hand; the rotation or
 * creation is complete either way. Shared by databases (ADR-020) and buckets
 * (ADR-021), because a project may hold both.
 */
enum AttachedRedeploySkipReason: string
{
    /** Never deployed, or stopped: its next deployment gets the new credential anyway. */
    case NoActiveDeployment = 'no_active_deployment';

    /** Whoever asked can no longer deploy the project, or their account is gone. */
    case RequesterCannotDeploy = 'requester_cannot_deploy';

    /** Whoever asked has terms to accept before deploying. */
    case LegalAcceptanceRequired = 'legal_acceptance_required';

    case ProjectSuspended = 'project_suspended';

    /** The project or workspace already runs as many deployments as its limit, often one queued before the rotation. */
    case ActiveDeploymentLimit = 'active_deployment_limit';

    case ActiveContainerLimit = 'active_container_limit';

    case BuildRateLimit = 'build_rate_limit';

    case RepositoryAccessRevoked = 'repository_access_revoked';

    case DatabaseEndpointUnavailable = 'database_endpoint_unavailable';

    /** The project's attached database is still being created, or failed to be. */
    case DatabaseNotReady = 'database_not_ready';

    /** The storage node of the project's attached bucket has not reported its endpoint. */
    case BucketEndpointUnavailable = 'bucket_endpoint_unavailable';

    /** The project's attached bucket is still being created, or failed to be. */
    case BucketNotReady = 'bucket_not_ready';

    /** The project's attached bucket is in its key uncertain window (BucketKeyWindow). */
    case BucketKeyRotating = 'bucket_key_rotating';

    case ReleaseCommandUnsupported = 'release_command_unsupported';

    /** The deployment would run more worker containers than the workspace's `max_worker_containers` (ADR-022). */
    case WorkerContainerLimit = 'worker_container_limit';

    /** The project has worker processes and no node that could run it advertises `worker-processes`. */
    case WorkerProcessesUnsupported = 'worker_processes_unsupported';

    /** Only for `deploy_when_ready`: the database's creation failed or expired, so there is nothing to connect to. */
    case DatabaseProvisioningFailed = 'database_provisioning_failed';

    /** Anything else, reported to the error tracker. */
    case Failed = 'failed';
}
