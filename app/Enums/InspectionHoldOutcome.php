<?php

declare(strict_types=1);

namespace App\Enums;

/** What settling one held deployment did (DeploymentInspectionHold). */
enum InspectionHoldOutcome: string
{
    /** The port is known: the command was given it and may now be claimed. */
    case Released = 'released';

    /** The inspection failed or did not finish in time: the deployment failed. */
    case Failed = 'failed';

    /** The inspection is still running and the deadline has not passed. */
    case Waiting = 'waiting';

    /** Nothing held any more: released, cancelled, or failed by another path. */
    case Gone = 'gone';
}
