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

    /**
     * Return the set of node capabilities required to handle this command type.
     * A node is eligible only if its capabilities intersect this set non-empty.
     */
    /** @return list<string> */
    public function requiredCapabilities(): array
    {
        return match ($this) {
            self::DeployProject => ['dockerfile-build', 'railpack-build'],
            self::RestartProject,
            self::StopProject,
            self::SleepProject,
            self::WakeProject,
            self::HealthCheck => ['docker-runtime'],
            self::RefreshRoute => ['caddy-file-routing'],
        };
    }
}
