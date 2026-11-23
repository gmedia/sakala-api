<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentQueueState: string
{
    /** A node that can run it holds the command; it starts on the next poll. */
    case Assigned = 'assigned';

    /** No eligible node exists right now; it waits without a deadline. */
    case WaitingForNode = 'waiting_for_node';

    /**
     * The project's inspection has not reported the port yet, so the command
     * is held; it is released, or the deployment fails, once it does.
     */
    case WaitingForInspection = 'waiting_for_inspection';
}
