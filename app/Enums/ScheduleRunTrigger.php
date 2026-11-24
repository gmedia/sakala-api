<?php

declare(strict_types=1);

namespace App\Enums;

/** What started a schedule run. */
enum ScheduleRunTrigger: string
{
    /** A slot of the cron expression, recorded by `schedules:dispatch`. */
    case Scheduled = 'scheduled';

    /** "Run now" by a workspace member; audited. */
    case Manual = 'manual';
}
