<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentCommandType: string
{
    case DeployProject = 'DeployProject';
    case RestartProject = 'RestartProject';
    case StopProject = 'StopProject';
    case SleepProject = 'SleepProject';
    case WakeProject = 'WakeProject';
    case HealthCheck = 'HealthCheck';
    case RefreshRoute = 'RefreshRoute';
}
