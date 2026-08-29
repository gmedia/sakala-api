<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentNodeStatus: string
{
    case Ready = 'ready';
    case Busy = 'busy';
    case Degraded = 'degraded';
    case Draining = 'draining';
    case Drained = 'drained';
    case Maintenance = 'maintenance';
    case Offline = 'offline';
}
