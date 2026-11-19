<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the API deployed an attached project on its own for nobody: after a
 * rotation that asked for redeploys, or once a database created with
 * `deploy_when_ready` became ready. The project keeps `redeploy_required` and
 * can be deployed by hand; the rotation or creation is complete either way.
 */
enum DatabaseRedeploySkipReason: string
{
    /** Never deployed, or stopped: its next deployment gets the new password anyway. */
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

    case ReleaseCommandUnsupported = 'release_command_unsupported';

    /** Only for `deploy_when_ready`: the database's creation failed or expired, so there is nothing to connect to. */
    case DatabaseProvisioningFailed = 'database_provisioning_failed';

    /** Anything else, reported to the error tracker. */
    case Failed = 'failed';
}
