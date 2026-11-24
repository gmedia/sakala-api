<?php

declare(strict_types=1);

namespace App\Enums;

/** Why the control plane disabled a schedule by itself. */
enum SchedulePauseReason: string
{
    /** `pause_after_failures` runs in a row failed, timed out, or were lost. */
    case ConsecutiveFailures = 'consecutive_failures';
}
