<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceType: string
{
    /** Created with every account; one per user, cannot be renamed, shared, or left. */
    case Personal = 'personal';

    case Team = 'team';
}
