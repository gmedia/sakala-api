<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a member's restart (RestartProject) stands: `restart.status` of
 * ProjectResource while it is open, and `restart.outcome` of the
 * `deployment.updated` broadcast once the node answered.
 */
enum ProjectRestartState: string
{
    /** Queued or running on the node. */
    case Pending = 'pending';

    /** The node restarted the containers. */
    case Succeeded = 'succeeded';

    /** The node could not restart them, or the command expired. */
    case Failed = 'failed';
}
