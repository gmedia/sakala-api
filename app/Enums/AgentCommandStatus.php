<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentCommandStatus: string
{
    case Pending = 'Pending';
    case Claimed = 'Claimed';
    case Running = 'Running';
    case Succeeded = 'Succeeded';
    case Failed = 'Failed';
    case Cancelled = 'Cancelled';
    case Expired = 'Expired';
}
