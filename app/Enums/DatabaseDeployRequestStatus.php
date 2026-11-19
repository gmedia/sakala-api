<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a deployment asked for with `deploy_when_ready` stands: the project
 * waits for its new database, got its deployment, or was skipped with a
 * DatabaseRedeploySkipReason.
 */
enum DatabaseDeployRequestStatus: string
{
    /** The database is still being created. */
    case Waiting = 'waiting';

    /** The database became ready and the deployment was created (`deployment_id`). */
    case Started = 'started';

    /** No deployment was created (`skipped_reason`); the project can still be deployed by hand. */
    case Skipped = 'skipped';
}
