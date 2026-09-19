<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectInspectionStatus: string
{
    /** An InspectProject command exists and has not finished. */
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** No inspection could be requested (e.g. the branch head could not be resolved). */
    case Unavailable = 'unavailable';
}
