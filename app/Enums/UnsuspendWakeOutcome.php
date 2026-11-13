<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether lifting a suspension started the project's kept container again.
 */
enum UnsuspendWakeOutcome: string
{
    /** A WakeProject was queued for the node that keeps the container. */
    case Requested = 'requested';

    /** The workspace already runs as many containers as it may; the project stays stopped. */
    case ContainerLimitReached = 'container_limit_reached';

    /** The node that keeps the container does not advertise `idle-sleep`, so it cannot wake it. */
    case NodeCannotWake = 'node_cannot_wake';

    /** No container is kept (never deployed, or stopped), or the project did not stop. */
    case NothingToWake = 'nothing_to_wake';
}
