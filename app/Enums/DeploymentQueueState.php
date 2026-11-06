<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentQueueState: string
{
    /** A node that can run it holds the command; it starts on the next poll. */
    case Assigned = 'assigned';

    /** No eligible node exists right now; it waits without a deadline. */
    case WaitingForNode = 'waiting_for_node';
}
